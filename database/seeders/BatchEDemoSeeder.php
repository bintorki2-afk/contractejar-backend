<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Contract;
use App\Models\ContractCharge;
use App\Models\ContractDataRequest;
use App\Models\ContractStatus;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\ReceivedContract;
use App\Models\UnitsReal;
use App\Modules\Users\Models\User;
use App\Support\ContractPricing;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * دفعة (هـ) — بيانات تجريبية لفحص QA على قاعدة final.sqlite (idempotent — تُعرَّف بأرقام طلب ثابتة).
 *
 *  (المجموعة 92001x نسخة مطابقة تبقى نظيفة لسيناريوهات QA.)
 *  E-UNPAID   920001  طلب غير مدفوع (الموظف يستلمه ثم يسجّل حوالة)
 *  E-PAID2U   920002  طلب مدفوع (Moyasar) + وحدتان + عنوان يدوي + كل المرفقات
 *  E-MAP      920003  طلب مدفوع + عنوان بالخريطة
 *  E-IMAGE    920004  طلب مدفوع + عنوان كصورة
 *  E-CHARGE   920005  طلب مدفوع + رسوم إضافية معلّقة 120 ر.س
 *  E-DATAREQ  920006  طلب مدفوع + طلب مرفق ناقص معلّق (صورة الصك)
 *  E-BANK     920007  طلب مدفوع بحوالة بنكية (إيصال على القرص الخاص)
 *  E-DIFF     920008  طلب مدفوع 249 ثم غُيّر نوع المستند إلى ورقي ⇒ فرق سعر 75 معلّق
 */
class BatchEDemoSeeder extends Seeder
{
    public const MOBILE = '0598800011';

    public function run(): void
    {
        // مجموعتان متطابقتان: 92000x للتجربة الحرة، و92001x تبقى «نظيفة» لسيناريوهات QA (لا تُلمس إلا من QA).
        $this->seedSet('92000');
        $this->seedSet('92001');

        $this->command?->info('Batch E demo fixtures ready: 920001..920008 + 920011..920018 (user mobile '.self::MOBILE.')');
    }

    private function seedSet(string $prefix): void
    {
        $user = User::query()->firstOrCreate(['mobile' => self::MOBILE], [
            'fname' => 'عميل', 'lname' => 'دفعة هـ', 'email' => 'batch-e@example.test',
            'password' => bcrypt('secret-demo'), 'is_active' => true, 'contact_mobile' => self::MOBILE, 'email_verified_at' => now(),
        ]);
        $employee = Employee::query()->where('email', 'admin@aqdi.com')->first() ?? Employee::query()->first();
        $city = City::query()->first();
        $regionId = $city?->region_id;

        $base = [
            'user_id' => $user->id, 'contract_type' => 'housing', 'instrument_type' => 'electronic', 'duration_preset' => '1_year', 'total_months' => 12,
            'step' => 7, 'is_draft' => false, 'is_delete' => 0, 'tenant_mobile' => '551234567', 'property_owner_mobile' => '559876543',
            'property_owner_id_num' => '1023456789', 'tenant_id_num' => '1098765432', 'property_owner_dob' => '10-05-1400', 'type_dob_property_owner' => 'hijri',
            'tenant_dob' => '15-05-1990', 'type_tenant_dob' => 'gregorian', 'instrument_number' => '440123456789', 'instrument_history' => '1440-05-10', 'type_instrument_history' => 'hijri',
            'property_type_id' => 1, 'property_usages_id' => 1, 'number_of_floors' => 2, 'number_of_units_in_realestate' => 4,
            'property_place_id' => $regionId, 'property_city_id' => $city?->id, 'neighborhood' => 'النرجس', 'street' => 'شارع الأمير سلطان', 'building_number' => '1234', 'postal_code' => '12345', 'extra_figure' => '6789',
            'contract_starting_date' => '2026-11-01', 'type_contract_starting_date' => 'gregorian', 'annual_rent_amount_for_the_unit' => 30000, 'payment_type_id' => 1, 'Guarantee_amount' => 2000, 'daily_fine' => 50,
            'contract_status_id' => ContractStatus::idFor('new'),
        ];

        $unpaid = $this->contract($prefix.'1', $base);

        $paid2u = $this->contract($prefix.'2', array_merge($base, ['contract_status_id' => ContractStatus::idFor('received_by_employee'), 'is_completed' => 1]));
        $this->units($paid2u, $user, 2);
        $this->attachments($paid2u);
        $this->moyasarPayment($paid2u);
        $this->received($paid2u, $employee);

        $map = $this->contract($prefix.'3', array_merge($base, ['is_completed' => 1, 'contract_status_id' => ContractStatus::idFor('under_review'), 'address_url' => 'https://maps.app.goo.gl/demo-'.$prefix.'3', 'latitude' => 24.7136, 'longitude' => 46.6753, 'neighborhood' => null, 'street' => null, 'building_number' => null, 'postal_code' => null, 'extra_figure' => null]));
        $this->units($map, $user, 1);
        $this->moyasarPayment($map);

        $image = $this->contract($prefix.'4', array_merge($base, ['is_completed' => 1, 'contract_status_id' => ContractStatus::idFor('under_review'), 'neighborhood' => null, 'street' => null, 'building_number' => null, 'postal_code' => null, 'extra_figure' => null]));
        $this->units($image, $user, 1);
        $this->attachments($image, ['image_address']);
        $this->moyasarPayment($image);

        $charge = $this->contract($prefix.'5', array_merge($base, ['is_completed' => 1, 'contract_status_id' => ContractStatus::idFor('received_by_employee')]));
        $this->units($charge, $user, 1);
        $this->moyasarPayment($charge);
        $this->received($charge, $employee);
        if (! ContractCharge::query()->where('contract_id', $charge->id)->where('kind', 'extra_fee')->exists()) {
            $row = ContractCharge::query()->create(['contract_id' => $charge->id, 'kind' => 'extra_fee', 'amount' => 120, 'message' => 'رسوم إضافة وحدة ثانية في إيجار', 'internal_reason' => 'طلب العميل', 'status' => 'pending', 'created_by' => $employee?->id]);
            $row->forceFill(['payment_key' => Payment::chargeKey((string) $charge->uuid, (int) $row->id)])->save();
        }

        $dataReq = $this->contract($prefix.'6', array_merge($base, ['is_completed' => 1, 'contract_status_id' => ContractStatus::idFor('received_by_employee')]));
        $this->units($dataReq, $user, 1);
        $this->attachments($dataReq);
        $this->moyasarPayment($dataReq);
        $this->received($dataReq, $employee);
        if (! ContractDataRequest::query()->where('contract_id', $dataReq->id)->where('status', 'pending')->exists()) {
            ContractDataRequest::query()->create([
                'contract_id' => $dataReq->id, 'section' => 'property',
                'items' => [['key' => 'deed_image_unclear', 'label' => 'صورة الصك غير واضحة', 'step' => 1, 'fields' => ['image_instrument', 'image_instrument_pages', 'image_instrument_from_the_front', 'image_instrument_from_the_back']]],
                'note' => 'الصورة مقصوصة من الأسفل', 'status' => 'pending', 'requested_by' => $employee?->id, 'requested_at' => now()->subHours(30),
            ]);
        }

        $bank = $this->contract($prefix.'7', array_merge($base, ['is_completed' => 1, 'contract_status_id' => ContractStatus::idFor('received_by_employee')]));
        $this->units($bank, $user, 1);
        $this->received($bank, $employee);
        $receiptPath = 'payments/receipts/'.$bank->id.'/demo-receipt.png';
        if (Storage::disk('local')->exists($receiptPath) && Storage::disk('local')->size($receiptPath) < 2048) {
            Storage::disk('local')->put($receiptPath, self::placeholderPng(self::ATTACHMENT_TITLES['receipt'], 'Order '.$bank->uuid.' · 264 SAR', 'receipt'));
        }
        if (! Payment::query()->where('contract_uuid', (string) $bank->uuid)->where('status', 'success')->exists()) {
            Storage::disk('local')->put('payments/receipts/'.$bank->id.'/demo-receipt.png', self::placeholderPng(self::ATTACHMENT_TITLES['receipt'], 'Order '.$bank->uuid.' · 264 SAR', 'receipt'));
            Payment::query()->create([
                'name' => 'Bank transfer '.$bank->uuid, 'amount' => ContractPricing::total($bank), 'contract_uuid' => (string) $bank->uuid, 'contract_id' => $bank->id,
                'kind' => 'bank_transfer', 'tran_currency' => 'SAR', 'payment_method' => 'bank_transfer', 'payment_brand' => 'bank', 'status' => 'success',
                'payment_date' => now()->subDay()->toDateString(), 'employee_id' => $employee?->id, 'receipt_path' => 'payments/receipts/'.$bank->id.'/demo-receipt.png', 'reference' => 'TRF-'.$prefix.'7',
            ]);
        }

        // 920008: دُفع 249 (إلكتروني، عداد الكهرباء باسم المالك) ثم غُيّر نوع المستند إلى ورقي ⇒ فرق 75 بالضبط.
        $diff = $this->contract($prefix.'8', array_merge($base, ['is_completed' => 1, 'contract_status_id' => ContractStatus::idFor('received_by_employee')]));
        $this->units($diff, $user, 1, 'owner');
        $this->moyasarPayment($diff, 249.0);
        $this->received($diff, $employee);
        $pendingDiff = ContractCharge::query()->where('contract_id', $diff->id)->where('kind', 'price_difference')->first();
        if ($pendingDiff === null) {
            // لقطة الفاتورة الأصلية (249) قبل التعديل — كما يحدث فعلياً عند الدفع.
            app(\App\Services\ContractInvoiceService::class)->forContract($diff->fresh());
            $diff->forceFill(['instrument_type' => 'old_handwritten'])->save();
            app(\App\Services\Charges\ChargeService::class)->syncPriceDifference($diff->fresh(), $employee, ['instrument_type' => ['label' => 'نوع المستند', 'before' => 'electronic', 'after' => 'old_handwritten']]);
        }

    }

    /** @param array<string, mixed> $attributes */
    private function contract(string $uuid, array $attributes): Contract
    {
        $existing = Contract::query()->where('uuid', $uuid)->first();
        if ($existing) {
            return $existing;
        }
        $contract = Contract::query()->create($attributes);
        DB::table('contracts')->where('id', $contract->id)->update(['uuid' => $uuid]);

        return $contract->fresh();
    }

    private function units(Contract $contract, User $user, int $count, string $electricityOwnership = 'tenant'): void
    {
        if ($contract->units()->count() >= $count) {
            UnitsReal::query()->whereIn('id', $contract->units()->pluck('real_units.id'))->update(['electricity_meter_ownership' => $electricityOwnership]);

            return;
        }
        for ($i = 1; $i <= $count; $i++) {
            $unit = UnitsReal::query()->create(UnitsReal::attributesForApi([
                'user_id' => $user->id, 'unit_number' => (string) (10 + $i), 'unit_type_id' => 1, 'unit_usage_id' => 1, 'floor_number' => (string) $i, 'unit_area' => (string) (120 + 30 * $i),
                'tootal_rooms' => (string) (2 + $i), 'The_number_of_halls' => '1', 'The_number_of_kitchens' => '1', 'The_number_of_toilets' => '2', 'split_ac' => 3, 'window_ac' => 0,
                'furnished' => $i === 1, 'type_furnished' => $i === 1 ? 'new' : null, 'kitchen_tank' => true,
                'electricity_meter' => true, 'electricity_meter_number' => 'E-'.$contract->uuid.'-'.$i, 'electricity_meter_ownership' => $electricityOwnership,
                'water_meter' => true, 'water_meter_number' => 'W-'.$contract->uuid.'-'.$i, 'water_meter_ownership' => 'shared', 'water_shared_monthly_fee' => 150,
                'contract_type' => 'housing',
            ]));
            $contract->units()->attach($unit->id, ['real_estate_id' => null]);
        }
    }

    /** @var array<string, string> */
    private const ATTACHMENT_TITLES = [
        'image_instrument' => 'DEED / TITLE DOCUMENT',
        'image_address' => 'NATIONAL ADDRESS',
        'copy_of_the_authorization_or_agency' => 'POWER OF ATTORNEY',
        'copy_of_the_owner_record' => 'TENANT ID / RECORD',
        'copy_of_the_endowment_registration_certificate' => 'ENDOWMENT CERTIFICATE',
        'copy_of_the_trusteeship_deed' => 'TRUSTEESHIP DEED',
        'receipt' => 'BANK TRANSFER RECEIPT',
    ];

    /**
     * @param list<string>|null $only
     * متابعة دفعة (هـ) #3: صور placeholder حقيقية المظهر (600×800 PNG بعنوان المرفق ورقم الطلب) — تُعاد كتابة الصور الصغيرة (1×1) القديمة.
     */
    private function attachments(Contract $contract, ?array $only = null): void
    {
        $fields = $only ?? ['image_instrument', 'image_address', 'copy_of_the_authorization_or_agency', 'copy_of_the_owner_record', 'copy_of_the_endowment_registration_certificate', 'copy_of_the_trusteeship_deed'];
        $update = [];
        foreach ($fields as $field) {
            $existing = $contract->getAttributes()[$field] ?? null;
            $path = filled($existing) ? ltrim((string) $existing, '/') : 'contracts/deeds/'.$contract->id.'/'.$field.'.png';
            if (! filled($existing) || Storage::disk('local')->size($path) < 2048) {
                Storage::disk('local')->put($path, self::placeholderPng(self::ATTACHMENT_TITLES[$field] ?? strtoupper($field), 'Order '.$contract->uuid, $field));
            }
            if (! filled($existing)) {
                $update[$field] = $path;
            }
        }
        if ($update !== []) {
            DB::table('contracts')->where('id', $contract->id)->update($update);
        }
    }

    /** صورة مستند تجريبية 600×800 (GD) — عنوان + رقم الطلب + سطور + ختم. */
    public static function placeholderPng(string $title, string $subtitle, string $key, int $w = 600, int $h = 800): string
    {
        $im = imagecreatetruecolor($w, $h);
        $paper = imagecolorallocate($im, 250, 248, 240);
        $ink = imagecolorallocate($im, 40, 40, 40);
        $muted = imagecolorallocate($im, 150, 150, 150);
        $brand = imagecolorallocate($im, 15, 118, 110);
        $stamp = imagecolorallocate($im, 185, 28, 28);
        imagefilledrectangle($im, 0, 0, $w, $h, $paper);
        imagerectangle($im, 14, 14, $w - 15, $h - 15, $brand);
        imagerectangle($im, 18, 18, $w - 19, $h - 19, $brand);
        for ($y = 220; $y < $h - 160; $y += 34) {
            imageline($im, 50, $y, $w - 50, $y, $muted);
        }
        imagerectangle($im, $w - 190, $h - 150, $w - 50, $h - 60, $stamp);
        imagerectangle($im, $w - 186, $h - 146, $w - 54, $h - 64, $stamp);

        $font = null;
        foreach (['/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf'] as $candidate) {
            if (is_file($candidate)) {
                $font = $candidate;
                break;
            }
        }
        if ($font !== null && function_exists('imagettftext')) {
            imagettftext($im, 26, 0, 50, 90, $brand, $font, $title);
            imagettftext($im, 18, 0, 50, 130, $ink, $font, $subtitle);
            imagettftext($im, 12, 0, 50, 165, $muted, $font, 'DEMO PLACEHOLDER — '.$key);
            imagettftext($im, 14, 0, $w - 178, $h - 100, $stamp, $font, 'DEMO STAMP');
            imagettftext($im, 11, 0, 50, $h - 40, $muted, $font, 'contractejar.com · batch E fixture · not a real document');
        } else {
            imagestring($im, 5, 50, 70, $title, $brand);
            imagestring($im, 4, 50, 110, $subtitle, $ink);
            imagestring($im, 3, 50, 150, 'DEMO PLACEHOLDER - '.$key, $muted);
        }

        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    private function moyasarPayment(Contract $contract, ?float $amount = null): void
    {
        if (Payment::query()->where('contract_uuid', (string) $contract->uuid)->where('status', 'success')->exists()) {
            return;
        }
        Payment::query()->create([
            'name' => 'Contract '.$contract->uuid, 'amount' => $amount ?? ContractPricing::total($contract), 'contract_uuid' => (string) $contract->uuid, 'contract_id' => $contract->id,
            'kind' => 'original', 'tran_currency' => 'SAR', 'payment_method' => 'creditcard', 'payment_brand' => 'mada', 'status' => 'success',
            'payment_date' => now()->subDays(2)->toDateString(), 'gateway_payment_id' => 'pay_demo_'.$contract->uuid,
        ]);
        app(\App\Services\ContractStatusHistoryService::class)->ensureSeeded($contract->fresh());
    }

    private function received(Contract $contract, ?Employee $employee): void
    {
        if ($employee === null || ReceivedContract::query()->where('contract_id', $contract->id)->exists()) {
            return;
        }
        ReceivedContract::query()->create(['contract_id' => $contract->id, 'employee_id' => $employee->id, 'status' => 'finish', 'date_of_received' => now()->toDateString()]);
    }
}
