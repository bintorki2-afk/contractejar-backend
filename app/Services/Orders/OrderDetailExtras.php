<?php

namespace App\Services\Orders;

use App\Models\Contract;
use App\Models\UnitsReal;
use App\Modules\Auth\Support\AuthMobile;
use App\Modules\Users\Models\User;
use App\Services\Charges\ChargeService;
use App\Services\DataRequests\ContractDataRequestService;
use App\Services\Payments\ContractPaymentState;
use App\Support\ContractFrontendStatus;
use App\Support\ContractJourney;
use App\Support\DeedImage;
use App\Support\HijriDate;
use Illuminate\Support\Carbon;

/**
 * إضافات تفاصيل الطلب (دفعة هـ — 2.6): جوال منشئ الطلب، طلبات العميل السابقة، طريقة إدخال العنوان
 * والعنوان المهيكل، المستند، الوحدات المهيكلة، تقدّم الإدخال في إيجار، حالة الدفع والتفاصيل، الرسوم،
 * طلبات المرفق الناقص، ورحلة الطلب (3 خطوات + الحالة الجانبية).
 */
class OrderDetailExtras
{
    /** ترتيب حقول الوحدة كما في معالج الموقع. */
    public const UNIT_FIELDS = [
        'unit_number' => ['label' => 'رقم الوحدة', 'icon' => 'hash'],
        'unit_type' => ['label' => 'نوع الوحدة', 'icon' => 'building'],
        'floor_number' => ['label' => 'الدور', 'icon' => 'layers'],
        'unit_area' => ['label' => 'المساحة (م²)', 'icon' => 'ruler'],
        'rooms' => ['label' => 'الغرف', 'icon' => 'bed'],
        'halls' => ['label' => 'الصالات', 'icon' => 'sofa'],
        'baths' => ['label' => 'دورات المياه', 'icon' => 'bath'],
        'kitchens' => ['label' => 'المطابخ', 'icon' => 'chef-hat'],
        'ac' => ['label' => 'المكيفات', 'icon' => 'air-vent'],
        'furnished' => ['label' => 'مؤثثة', 'icon' => 'armchair'],
        'kitchen_cabinets' => ['label' => 'خزائن مطبخ', 'icon' => 'box'],
        'electricity_meter' => ['label' => 'عداد الكهرباء', 'icon' => 'zap'],
        'water_meter' => ['label' => 'عداد المياه', 'icon' => 'droplets'],
        'parking' => ['label' => 'مواقف', 'icon' => 'car'],
    ];

    public function __construct(
        private readonly ContractPaymentState $paymentState,
        private readonly ChargeService $charges,
        private readonly ContractDataRequestService $dataRequests,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Contract $contract): array
    {
        $contract->loadMissing(['user', 'units.unitType', 'units.unitUsage', 'propertyRegion', 'propertyCity', 'receivedContract.employee']);
        $creator = $this->creatorMobile($contract);
        $details = $this->paymentState->details($contract);

        return [
            'creator_mobile' => $creator,
            'customer_orders_summary' => $this->customerOrdersSummary($contract, $creator['dial'] ?? null),
            ...$this->address($contract),
            'document' => $this->document($contract),
            'units' => $this->units($contract),
            'units_count' => $contract->units->count(),
            'ejar_entry_progress' => EjarEntryProgress::for($contract),
            'payment_state' => $details['state'],
            'payment_details' => $details,
            'charges' => $this->charges->forAdmin($contract),
            'data_requests' => $this->dataRequests->all($contract)->map(fn ($r) => $this->dataRequests->toAdminArray($r))->values()->all(),
            'pending_data_requests' => $this->dataRequests->pendingForAdmin($contract),
            'data_request_pending' => $this->dataRequests->pendingSummary($contract),
            'journey' => ContractJourney::for($contract),
            'journey_side_state' => ContractJourney::sideState($contract),
            'journey_sentence' => ContractJourney::RULE_SENTENCE,
            'attachments' => $this->attachments($contract),
        ];
    }

    /**
     * @return array{local: string|null, dial: string|null, whatsapp_url: string|null, source: string|null}
     */
    public function creatorMobile(Contract $contract): array
    {
        $user = $contract->user;
        $candidates = [
            ['value' => $user?->contact_mobile, 'source' => 'contact_mobile'],
            ['value' => $user?->mobile, 'source' => 'mobile'],
        ];
        foreach ($candidates as $c) {
            $digits = preg_replace('/\D+/', '', (string) ($c['value'] ?? '')) ?? '';
            if ($digits === '') {
                continue;
            }
            if (str_starts_with($digits, '00')) {
                $digits = substr($digits, 2);
            }
            if (str_starts_with($digits, '0')) {
                $digits = '966'.substr($digits, 1);
            } elseif (strlen($digits) === 9 && str_starts_with($digits, '5')) {
                $digits = '966'.$digits;
            }
            if (preg_match('/^9665\d{8}$/', $digits) !== 1) {
                continue;
            }

            return [
                'local' => '0'.substr($digits, 3),
                'dial' => $digits,
                'whatsapp_url' => 'https://wa.me/'.$digits,
                'source' => $c['source'],
            ];
        }

        return ['local' => null, 'dial' => null, 'whatsapp_url' => null, 'source' => null];
    }

    /**
     * طلبات العميل الأخرى بنفس الجوال (المدفوع أولاً).
     *
     * @return array{count: int, count_paid: int, count_unpaid: int, items: list<array<string, mixed>>}
     */
    public function customerOrdersSummary(Contract $contract, ?string $dial): array
    {
        $userIds = [];
        if ($contract->user_id) {
            $userIds[] = (int) $contract->user_id;
        }
        if ($dial !== null) {
            $variants = AuthMobile::lookupVariants($dial);
            $ids = User::query()
                ->where(fn ($q) => $q->whereIn('mobile', $variants)->orWhereIn('contact_mobile', $variants))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            $userIds = array_merge($userIds, $ids);
        }
        $userIds = array_values(array_unique($userIds));
        if ($userIds === []) {
            return ['count' => 0, 'count_paid' => 0, 'count_unpaid' => 0, 'items' => []];
        }

        $rows = Contract::query()->whereIn('user_id', $userIds)->whereKeyNot($contract->id)
            ->notDeleted()->reachedAdminOrderStep()
            ->with(['contractStatus', 'contractPayments' => fn ($q) => $q->where('status', 'success'), 'paymentRows' => fn ($q) => $q->where('status', 'success'), 'charges', 'refunds'])
            ->orderByDesc('is_completed')->orderByDesc('id')->limit(50)->get();

        $items = $rows->map(function (Contract $c) {
            $status = ContractFrontendStatus::for($c);
            $payment = $this->paymentState->summaryForList($c);

            return [
                'uuid' => (string) $c->uuid,
                'id' => $c->id,
                'type' => $c->contract_type,
                'type_label' => Contract::contractTypeLabel((string) $c->contract_type, 'ar'),
                'status_key' => \App\Models\ContractStatus::keyForId($c->contract_status_id ? (int) $c->contract_status_id : null) ?? $status['status'],
                'status_label' => $status['status_label'],
                'is_paid' => (bool) $c->is_completed,
                'payment_state' => ['status' => $payment['status'], 'status_label' => $payment['status_label'], 'label' => $payment['label'], 'paid_total' => $payment['paid_total']],
                'created_at' => $c->created_at?->toIso8601String(),
            ];
        })->values()->all();

        $paid = $rows->where('is_completed', 1)->count();

        return [
            'count' => $rows->count(),
            'count_paid' => $paid,
            'count_unpaid' => $rows->count() - $paid,
            'items' => $items,
        ];
    }

    /**
     * @return array{address_entry_mode: string, address_modes_available: list<string>, address: array<string, mixed>}
     */
    public function address(Contract $contract): array
    {
        $region = $contract->propertyRegion;
        $city = $contract->propertyCity;
        $lat = $contract->latitude !== null && $contract->latitude !== '' ? (float) $contract->latitude : null;
        $lng = $contract->longitude !== null && $contract->longitude !== '' ? (float) $contract->longitude : null;
        $mapUrl = filled($contract->address_url) ? (string) $contract->address_url : (($lat !== null && $lng !== null) ? 'https://maps.google.com/?q='.$lat.','.$lng : null);
        $hasManual = filled($contract->street) || filled($contract->building_number) || filled($contract->neighborhood) || filled($contract->postal_code);
        $hasImage = filled($contract->getAttributes()['image_address'] ?? null);
        $hasMap = $mapUrl !== null;

        $available = array_keys(array_filter(['manual' => $hasManual, 'map' => $hasMap, 'image' => $hasImage]));

        $mode = match (true) {
            $hasMap => 'map',
            $hasImage && ! $hasManual => 'image',
            default => 'manual',
        };

        return [
            'address_entry_mode' => $mode,
            'address_modes_available' => array_values($available),
            'address' => [
                'region' => $this->name($region),
                'region_id' => $contract->property_place_id,
                'city' => $this->name($city),
                'city_id' => $contract->property_city_id,
                'district' => $contract->neighborhood,
                'street' => $contract->street,
                'building_no' => $contract->building_number,
                'additional_no' => $contract->extra_figure,
                'postal_code' => $contract->postal_code,
                'map_url' => $mapUrl,
                'lat' => $lat,
                'lng' => $lng,
                'image_key' => $hasImage ? 'image_address' : null,
                'image_url' => $hasImage ? DeedImage::signedUrl($contract, 'image_address') : null,
                'line1' => implode(' · ', array_filter([$this->name($region), $this->name($city), $contract->neighborhood, $contract->street])),
                'line2' => implode(' · ', array_filter([
                    filled($contract->building_number) ? 'رقم المبنى '.$contract->building_number : null,
                    filled($contract->extra_figure) ? 'الرقم الإضافي '.$contract->extra_figure : null,
                    filled($contract->postal_code) ? 'الرمز البريدي '.$contract->postal_code : null,
                ])),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function document(Contract $contract): array
    {
        $key = Contract::normalizeInstrumentType($contract->instrument_type) ?? $contract->instrument_type;
        [$hijri, $gregorian] = $this->bothCalendars($contract->instrument_history, $contract->type_instrument_history);

        return [
            'type_key' => $key,
            'type_label' => $key ? Contract::instrumentTypeLabel((string) $key, 'ar') : null,
            'deed_number' => $contract->instrument_number,
            'deed_date_raw' => $contract->instrument_history,
            'deed_date_type' => $contract->type_instrument_history,
            'deed_date_hijri' => $hijri,
            'deed_date_gregorian' => $gregorian,
            'registry_number' => $contract->real_estate_registry_number,
            'surcharge_applies' => \App\Support\DocumentSurcharge::appliesTo($key),
            'line' => implode(' · ', array_filter([
                $key ? 'نوع المستند: '.Contract::instrumentTypeLabel((string) $key, 'ar') : null,
                filled($contract->instrument_number) ? 'رقم الصك: '.$contract->instrument_number : null,
                $hijri || $gregorian ? 'تاريخه: '.implode(' — ', array_filter([$hijri ? $hijri.' هـ' : null, $gregorian ? $gregorian.' م' : null])) : null,
            ])),
        ];
    }

    /**
     * الوحدات بترتيب حقول المعالج + ac_count + furnished + meters[].
     *
     * @return list<array<string, mixed>>
     */
    public function units(Contract $contract): array
    {
        $units = $contract->relationLoaded('units') ? $contract->units : $contract->units()->with(['unitType', 'unitUsage'])->get();
        if ($units->isEmpty() && (filled($contract->unit_number) || filled($contract->unit_area))) {
            // عقد قديم بلا صفوف وحدات: حقول الوحدة على العقد نفسه.
            return [$this->unitFromContract($contract)];
        }

        return $units->values()->map(fn (UnitsReal $u, int $i) => $this->unit($u, $i + 1, $contract))->all();
    }

    /** @return array<string, mixed> */
    private function unit(UnitsReal $u, int $index, Contract $contract): array
    {
        $base = (new \App\Http\Resources\Api\V2\UnitResource($u))->resolve();
        $split = (int) ($u->split_ac ?? 0);
        $window = (int) ($u->window_ac ?? 0);
        $acTotal = $split + $window;
        if ($acTotal === 0 && is_numeric($u->number_of_unit_air_conditioners)) {
            $acTotal = (int) $u->number_of_unit_air_conditioners;
        }

        return array_merge($base, [
            'index' => $index,
            'title' => 'الوحدة '.$index.(filled($u->unit_number) ? ' — رقم '.$u->unit_number : ''),
            'unit_type_name' => $this->name($u->unitType),
            'unit_usage_name' => $this->name($u->unitUsage),
            'rooms' => $u->tootal_rooms,
            'halls' => $u->The_number_of_halls,
            'baths' => $u->The_number_of_toilets,
            'kitchens' => $u->The_number_of_kitchens,
            'ac_count' => $acTotal,
            'ac_split' => $split,
            'ac_window' => $window,
            'ac_label' => $acTotal > 0 ? implode(' + ', array_filter([$split > 0 ? 'سبليت × '.$split : null, $window > 0 ? 'شباك × '.$window : null])) : null,
            'furnished' => (bool) $u->furnished,
            'furnished_label' => (bool) $u->furnished ? $this->furnishedLabel($u->type_furnished) : null,
            'kitchen_cabinets' => (bool) $u->kitchen_tank,
            'parking' => $u->Number_parking_spaces,
            'meters' => $this->meters($u, $contract),
            'fields_order' => array_keys(self::UNIT_FIELDS),
            'field_labels' => array_map(fn ($f) => $f['label'], self::UNIT_FIELDS),
            'field_icons' => array_map(fn ($f) => $f['icon'], self::UNIT_FIELDS),
        ]);
    }

    /** @return array<string, mixed> */
    private function unitFromContract(Contract $c): array
    {
        $split = (int) ($c->split_ac ?? 0);
        $window = (int) ($c->window_ac ?? 0);
        $acTotal = $split + $window ?: (int) ($c->number_of_unit_air_conditioners ?? 0);

        return [
            'index' => 1,
            'id' => null,
            'title' => 'الوحدة 1'.(filled($c->unit_number) ? ' — رقم '.$c->unit_number : ''),
            'unit_number' => $c->unit_number,
            'unit_type_id' => $c->unit_type_id,
            'unit_type_name' => $this->name($c->unitType),
            'unit_usage_id' => $c->unit_usage_id,
            'unit_usage_name' => $this->name($c->unitUsage),
            'floor_number' => $c->floor_number,
            'unit_area' => $c->unit_area,
            'rooms' => $c->tootal_rooms ?? $c->number_of_rooms,
            'halls' => $c->The_number_of_halls,
            'baths' => $c->The_number_of_toilets ?? $c->The_number_of_the_toilet,
            'kitchens' => $c->The_number_of_kitchens,
            'ac_count' => $acTotal,
            'ac_split' => $split,
            'ac_window' => $window,
            'ac_label' => $acTotal > 0 ? 'سبليت × '.$acTotal : null,
            'furnished' => (bool) $c->furnished,
            'furnished_label' => (bool) $c->furnished ? 'نعم' : null,
            'kitchen_cabinets' => (bool) $c->kitchen_tank,
            'parking' => $c->Number_parking_spaces,
            'electricity_meter_number' => $c->electricity_meter_number,
            'water_meter_number' => $c->water_meter_number,
            'electricity_meter_ownership' => $c->electricity_meter_ownership,
            'water_meter_ownership' => $c->water_meter_ownership,
            'meters' => $this->meters($c, $c),
            'fields_order' => array_keys(self::UNIT_FIELDS),
            'field_labels' => array_map(fn ($f) => $f['label'], self::UNIT_FIELDS),
            'field_icons' => array_map(fn ($f) => $f['icon'], self::UNIT_FIELDS),
        ];
    }

    /**
     * @return list<array{kind: string, label: string, number: string|null, ownership: string|null, ownership_label: string, shared: bool, monthly_amount: float|null, icon: string}>
     */
    private function meters(object $u, Contract $contract): array
    {
        $out = [];
        foreach (['electricity' => ['label' => 'عداد الكهرباء', 'icon' => 'zap'], 'water' => ['label' => 'عداد المياه', 'icon' => 'droplets']] as $kind => $meta) {
            $ownership = $u->{$kind.'_meter_ownership'} ?? null;
            $number = $u->{$kind.'_meter_number'} ?? null;
            $hasMeter = (bool) ($u->{$kind.'_meter'} ?? false) || filled($number) || filled($ownership);
            if (! $hasMeter) {
                continue;
            }
            $shared = $ownership === 'shared';
            $monthly = $u->{$kind.'_shared_monthly_fee'} ?? null;
            $out[] = [
                'kind' => $kind,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'number' => filled($number) ? (string) $number : null,
                'ownership' => $ownership,
                'ownership_label' => match ($ownership) {
                    'tenant' => 'باسم المستأجر',
                    'owner' => 'باسم المالك',
                    'shared' => 'مشترك',
                    default => '—',
                },
                'shared' => $shared,
                'monthly_amount' => $shared && $monthly !== null && $monthly !== '' ? (float) $monthly : null,
                'summary' => $shared
                    ? 'مشترك'.($monthly !== null && $monthly !== '' ? ' · '.rtrim(rtrim(number_format((float) $monthly, 2, '.', ''), '0'), '.').' ر.س/شهر' : '')
                    : (match ($ownership) { 'tenant' => 'باسم المستأجر', 'owner' => 'باسم المالك', default => '' }),
            ];
        }

        return $out;
    }

    /**
     * المرفقات الموجودة فقط (روابط موقّعة) بترتيب تبويبات العارض.
     *
     * @return list<array{key: string, label: string, url: string, pages?: list<string>}>
     */
    public function attachments(Contract $contract): array
    {
        $map = [
            'image_instrument' => 'الصك',
            'image_instrument_from_the_front' => 'الصك (الوجه)',
            'image_instrument_from_the_back' => 'الصك (الخلف)',
            'copy_of_the_trusteeship_deed' => 'صك النظارة',
            'copy_of_the_endowment_registration_certificate' => 'شهادة الوقف',
            'Image_inheritance_certificate' => 'صك حصر الورثة',
            'copy_power_of_attorney_from_heirs_to_agent' => 'وكالة الورثة للوكيل',
            'copy_of_guardians_power_of_attorney_for_agent' => 'وكالة الأوصياء',
            'copy_of_the_authorization_or_agency' => 'الوكالة',
            'copy_of_the_owner_record' => 'هوية المستأجر / السجل',
            'image_address' => 'العنوان الوطني',
        ];
        $out = [];
        $attrs = $contract->getAttributes();
        foreach ($map as $key => $label) {
            $raw = $attrs[$key] ?? null;
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $url = DeedImage::isField($key) ? DeedImage::signedUrl($contract, $key) : $this->publicUrl($raw);
            if ($url === null) {
                continue;
            }
            $row = ['key' => $key, 'label' => $label, 'url' => $url];
            if ($key === 'image_instrument') {
                $pages = DeedImage::signedPageUrls($contract);
                if ($pages !== []) {
                    $row['pages'] = $pages;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    private function publicUrl(string $raw): ?string
    {
        $path = ltrim(trim($raw), '/');
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return url('storage/'.$path);
    }

    /** @return array{0: string|null, 1: string|null} [hijri DD/MM/YYYY, gregorian YYYY-MM-DD] */
    private function bothCalendars(?string $stored, ?string $type): array
    {
        $raw = trim((string) $stored);
        if ($raw === '') {
            return [null, null];
        }
        try {
            $hijri = HijriDate::parseStored($raw);
            if ($hijri !== null && ($type === 'hijri' || $type === null || $type === '')) {
                [$y, $m, $d] = $hijri;

                return [sprintf('%02d/%02d/%04d', $d, $m, $y), HijriDate::toGregorian($y, $m, $d)->format('Y-m-d')];
            }
            $greg = Carbon::parse($raw);
            [$hy, $hm, $hd] = HijriDate::fromGregorian($greg);

            return [sprintf('%02d/%02d/%04d', $hd, $hm, $hy), $greg->format('Y-m-d')];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    private function furnishedLabel(mixed $type): string
    {
        $t = is_string($type) ? trim($type) : '';

        return match (true) {
            $t === '' || in_array($t, ['1', '0', 'true', 'false'], true) => 'نعم',
            $t === 'new' => 'نعم — أثاث جديد',
            $t === 'used' => 'نعم — أثاث مستعمل',
            default => 'نعم — '.$t,
        };
    }

    private function name(?object $model): ?string
    {
        if ($model === null) {
            return null;
        }
        foreach (['name_trans', 'name_ar', 'name', 'name_en'] as $attr) {
            $v = $model->{$attr} ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }
}
