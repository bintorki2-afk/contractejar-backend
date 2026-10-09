<?php

namespace App\Services\Orders;

use App\Models\Contract;
use App\Support\ContractEndDate;
use App\Support\DocFee;
use App\Support\HijriDate;
use Illuminate\Support\Carbon;

/**
 * «نسخ بيانات إيجار» (دفعة د — ب15): كتل نصية بترتيب إدخال منصة إيجار
 * (المؤجر → المستأجر → العقار → الوحدات → المالية → التواريخ → العدادات) بتسميات عربية،
 * أرقام لاتينية (ASCII) والتاريخ هجري + ميلادي.
 */
class EjarCopyService
{
    private const OWNERSHIP = ['tenant' => 'باسم المستأجر', 'owner' => 'باسم المالك', 'shared' => 'مشترك'];

    /**
     * @return array{order_number: string, blocks: list<array{key: string, title: string, fields: list<array{key: string, label: string, value: string}>, text: string}>, text: string, generated_at: string}
     */
    public function build(Contract $contract): array
    {
        $contract->loadMissing([
            'user', 'units.unitType', 'units.unitUsage', 'propertyType', 'propertyUsages', 'propertyCity', 'propertyRegion',
            'paymentType', 'tenantRole', 'tenantEntityCity', 'tenantEntityRegion', 'contractTermInYears',
        ]);

        $blocks = array_values(array_filter([
            $this->block('lessor', 'بيانات المؤجر', [
                'name_owner' => ['اسم المالك', $contract->name_owner],
                'property_owner_id_num' => ['رقم هوية المالك', $contract->property_owner_id_num],
                'property_owner_dob' => ['تاريخ ميلاد المالك', $this->date($contract->property_owner_dob, $contract->type_dob_property_owner)],
                'property_owner_mobile' => ['جوال المالك', $this->mobile($contract->property_owner_mobile)],
                'property_owner_iban' => ['آيبان المالك', $contract->property_owner_iban],
                'property_owner_is_deceased' => ['المالك متوفى', $contract->property_owner_is_deceased ? 'نعم' : null],
                'id_num_of_property_owner_agent' => ['رقم هوية وكيل المالك', $contract->add_legal_agent_of_owner ? $contract->id_num_of_property_owner_agent : null],
                'dob_of_property_owner_agent' => ['تاريخ ميلاد وكيل المالك', $contract->add_legal_agent_of_owner ? $this->date($contract->dob_hijri_of_property_owner_agent ?: $contract->dob_gregorian_of_property_owner_agent, $contract->type_dob_property_owner_agent) : null],
                'mobile_of_property_owner_agent' => ['جوال وكيل المالك', $contract->add_legal_agent_of_owner ? $this->mobile($contract->mobile_of_property_owner_agent) : null],
                'agency_number_in_instrument_of_property_owner' => ['رقم الوكالة', $contract->add_legal_agent_of_owner ? $contract->agency_number_in_instrument_of_property_owner : null],
                'agency_instrument_date_of_property_owner' => ['تاريخ الوكالة', $contract->add_legal_agent_of_owner ? $this->date($contract->agency_instrument_date_of_property_owner, $contract->type_agency_instrument_date_of_property_owner) : null],
                'agent_iban_of_property_owner' => ['آيبان الوكيل', $contract->add_legal_agent_of_owner ? $contract->agent_iban_of_property_owner : null],
            ]),
            $this->block('tenant', 'بيانات المستأجر', [
                'tenant_id_num' => ['رقم هوية المستأجر', $contract->tenant_id_num],
                'tenant_dob' => ['تاريخ ميلاد المستأجر', $this->date($contract->tenant_dob ?: $contract->tenant_dob_gregorian, $contract->type_tenant_dob)],
                'tenant_mobile' => ['جوال المستأجر', $this->mobile($contract->tenant_mobile)],
                'tenant_entity' => ['المستأجر منشأة', $contract->tenant_entity ? 'نعم' : null],
                'tenant_entity_unified_registry_number' => ['الرقم الموحد للمنشأة', $contract->tenant_entity_unified_registry_number],
                'tenant_entity_city' => ['مدينة المنشأة', $this->name($contract->tenantEntityCity)],
                'tenant_role' => ['صفة المستأجر', $contract->tenantRole?->text_of_reason],
                'id_num_of_property_tenant_agent' => ['رقم هوية وكيل المستأجر', $contract->add_legal_agent_of_tenant ? $contract->id_num_of_property_tenant_agent : null],
                'mobile_of_property_tenant_agent' => ['جوال وكيل المستأجر', $contract->add_legal_agent_of_tenant ? $this->mobile($contract->mobile_of_property_tenant_agent) : null],
                'agency_number_in_instrument_of_property_tenant' => ['رقم وكالة المستأجر', $contract->add_legal_agent_of_tenant ? $contract->agency_number_in_instrument_of_property_tenant : null],
            ]),
            $this->block('property', 'بيانات العقار', [
                'instrument_type' => ['نوع الصك', Contract::instrumentTypeLabel((string) $contract->instrument_type, 'ar')],
                'instrument_number' => ['رقم الصك', $contract->instrument_number],
                'instrument_history' => ['تاريخ الصك', $this->date($contract->instrument_history, $contract->type_instrument_history)],
                'real_estate_registry_number' => ['رقم السجل العقاري', $contract->real_estate_registry_number],
                'date_first_registration' => ['تاريخ أول تسجيل', $this->date($contract->date_first_registration, $contract->type_date_first_registration)],
                'property_type' => ['نوع العقار', $this->name($contract->propertyType)],
                'property_usages' => ['استخدام العقار', $this->name($contract->propertyUsages)],
                'property_region' => ['المنطقة', $this->name($contract->propertyRegion)],
                'property_city' => ['المدينة', $this->name($contract->propertyCity)],
                'neighborhood' => ['الحي', $contract->neighborhood],
                'street' => ['الشارع', $contract->street],
                'building_number' => ['رقم المبنى', $contract->building_number],
                'postal_code' => ['الرمز البريدي', $contract->postal_code],
                'extra_figure' => ['الرقم الإضافي', $contract->extra_figure],
                'number_of_floors' => ['عدد الأدوار', $contract->number_of_floors],
                'number_of_units_in_realestate' => ['عدد الوحدات', $contract->number_of_units_in_realestate],
                'age_of_the_property' => ['عمر العقار', $contract->age_of_the_property],
            ]),
            ...$this->unitBlocks($contract),
            $this->block('financial', 'البيانات المالية', [
                'annual_rent_amount_for_the_unit' => ['قيمة الإيجار السنوي', $this->money($contract->annual_rent_amount_for_the_unit)],
                'payment_type' => ['دورية الدفع', $this->name($contract->paymentType)],
                'Guarantee_amount' => ['مبلغ الضمان', $this->money($contract->Guarantee_amount)],
                'deposit' => ['العربون', $this->money($contract->deposit)],
                'daily_fine' => ['غرامة التأخير اليومية', $this->money($contract->daily_fine)],
                'sub_delay' => ['السماح بالتأجير من الباطن', $contract->sub_delay ? 'نعم' : null],
            ]),
            $this->block('dates', 'التواريخ والمدة', [
                'contract_starting_date' => ['تاريخ بداية العقد', $this->date($contract->contract_starting_date, $contract->type_contract_starting_date)],
                'total_months' => ['مدة العقد (شهر)', (string) DocFee::contractMonths($contract)],
                'contract_end_date' => ['تاريخ نهاية العقد', ($end = $this->endDate($contract)) ? $this->bothFromCarbon($end) : null],
            ]),
            $this->block('meters', 'العدادات', $this->meterFields($contract)),
        ]));

        $text = implode("\n\n", array_map(static fn ($b) => $b['text'], $blocks));

        return [
            'order_number' => (string) $contract->uuid,
            'blocks' => $blocks,
            'text' => $text,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, array{0: string, 1: mixed}>  $fields
     * @return array<string, mixed>|null
     */
    private function block(string $key, string $title, array $fields): ?array
    {
        $rows = [];
        foreach ($fields as $fieldKey => [$label, $value]) {
            $value = $this->ascii($value);
            if ($value === null || $value === '') {
                continue;
            }
            $rows[] = ['key' => $fieldKey, 'label' => $label, 'value' => $value];
        }
        if ($rows === []) {
            return null;
        }

        return [
            'key' => $key,
            'title' => $title,
            'fields' => $rows,
            'text' => "— {$title} —\n".implode("\n", array_map(static fn ($r) => $r['label'].': '.$r['value'], $rows)),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function unitBlocks(Contract $contract): array
    {
        $blocks = [];
        foreach ($contract->units->values() as $i => $unit) {
            $ac = trim(implode(' + ', array_filter([
                $unit->split_ac ? 'سبليت '.$unit->split_ac : null,
                $unit->window_ac ? 'شباك '.$unit->window_ac : null,
            ])));
            $block = $this->block('unit_'.($i + 1), 'الوحدة '.($i + 1), [
                'unit_number' => ['رقم الوحدة', $unit->unit_number],
                'unit_type' => ['نوع الوحدة', $this->name($unit->unitType)],
                'unit_usage' => ['استخدام الوحدة', $this->name($unit->unitUsage)],
                'floor_number' => ['رقم الدور', $unit->floor_number],
                'unit_area' => ['المساحة (م²)', $unit->unit_area],
                'tootal_rooms' => ['عدد الغرف', $unit->tootal_rooms ?? $unit->number_of_rooms ?? null],
                'The_number_of_toilets' => ['دورات المياه', $unit->The_number_of_toilets ?? $unit->The_number_of_the_toilet ?? null],
                'The_number_of_halls' => ['الصالات', $unit->The_number_of_halls],
                'The_number_of_kitchens' => ['المطابخ', $unit->The_number_of_kitchens],
                'air_conditioners' => ['المكيفات', $ac !== '' ? $ac : null],
                'Number_parking_spaces' => ['المواقف', $unit->Number_parking_spaces],
                'furnished' => ['مؤثثة', $unit->furnished ? 'نعم' : null],
                'kitchen_tank' => ['خزان مطبخ', $unit->kitchen_tank ? 'نعم' : null],
            ]);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        if ($blocks === [] && filled($contract->unit_number)) {
            $legacy = $this->block('unit_1', 'الوحدة 1', [
                'unit_number' => ['رقم الوحدة', $contract->unit_number],
                'floor_number' => ['رقم الدور', $contract->floor_number],
                'unit_area' => ['المساحة (م²)', $contract->unit_area],
            ]);
            if ($legacy !== null) {
                $blocks[] = $legacy;
            }
        }

        return $blocks;
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    private function meterFields(Contract $contract): array
    {
        $fields = [];
        foreach ($contract->units->values() as $i => $unit) {
            $n = $i + 1;
            foreach (['electricity' => 'الكهرباء', 'water' => 'المياه'] as $kind => $label) {
                $number = $unit->{$kind.'_meter_number'} ?? null;
                $ownership = $unit->{$kind.'_meter_ownership'} ?? null;
                if (! filled($number) && ! filled($ownership)) {
                    continue;
                }
                $value = trim(($number ?: '—').' — '.(self::OWNERSHIP[$ownership] ?? (string) $ownership));
                if ($ownership === 'shared' && (float) ($unit->{$kind.'_shared_monthly_fee'} ?? 0) > 0) {
                    $value .= ' ('.$this->money($unit->{$kind.'_shared_monthly_fee'}).' شهرياً)';
                }
                $fields["unit_{$n}_{$kind}"] = ["عداد {$label} — الوحدة {$n}", $value];
            }
        }
        if ($fields === []) {
            foreach (['electricity' => 'الكهرباء', 'water' => 'المياه'] as $kind => $label) {
                $number = $contract->{$kind.'_meter_number'} ?? null;
                if (filled($number)) {
                    $fields[$kind] = ["عداد {$label}", $number.' — '.(self::OWNERSHIP[$contract->{$kind.'_meter_ownership'}] ?? '')];
                }
            }
        }

        return $fields;
    }

    private function endDate(Contract $contract): ?Carbon
    {
        try {
            return ContractEndDate::for($contract);
        } catch (\Throwable) {
            return null;
        }
    }

    /** تاريخ مخزّن (هجري أو ميلادي) ⇒ «DD/MM/YYYY هـ — YYYY-MM-DD م». */
    public function date(mixed $value, ?string $type = null): ?string
    {
        $raw = $this->ascii($value);
        if ($raw === null || $raw === '') {
            return null;
        }

        $hijri = HijriDate::parseStored($raw);
        if ($hijri !== null) {
            [$y, $m, $d] = $hijri;
            $greg = HijriDate::toGregorian($y, $m, $d);

            return sprintf('%02d/%02d/%04d هـ — %s م', $d, $m, $y, $greg->format('Y-m-d'));
        }

        try {
            return $this->bothFromCarbon(Carbon::parse($raw));
        } catch (\Throwable) {
            return $raw;
        }
    }

    private function bothFromCarbon(Carbon $date): string
    {
        [$hy, $hm, $hd] = HijriDate::fromGregorian($date);

        return sprintf('%02d/%02d/%04d هـ — %s م', $hd, $hm, $hy, $date->format('Y-m-d'));
    }

    /** جوال سعودي بصيغة نماذج إيجار: 05XXXXXXXX (المخزَّن 5XXXXXXXX). */
    private function mobile(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->ascii($value)) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00966')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '966')) {
            $digits = substr($digits, 3);
        }
        $digits = ltrim($digits, '0');

        return strlen($digits) === 9 ? '0'.$digits : (string) $this->ascii($value);
    }

    private function money(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($this->ascii($value))) {
            return null;
        }
        $n = (float) $this->ascii($value);
        if ($n <= 0) {
            return null;
        }

        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.').' ر.س';
    }

    private function name(?object $model): ?string
    {
        if ($model === null) {
            return null;
        }
        foreach (['name_trans', 'name_ar', 'name', 'name_en', 'text_of_reason', 'note'] as $attr) {
            if (isset($model->{$attr}) && filled($model->{$attr})) {
                return (string) $model->{$attr};
            }
        }

        return null;
    }

    /** أرقام عربية/فارسية ⇒ لاتينية. */
    private function ascii(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'نعم' : null;
        }

        return trim(strtr((string) $value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]));
    }
}
