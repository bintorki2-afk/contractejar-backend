<?php

namespace App\Services\Orders;

use App\Models\Contract;
use App\Models\Employee;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * تعديل الحقول الصغيرة للطلب من اللوحة مع التدقيق (دفعة د — ب17).
 */
class AdminOrderPatchService
{
    /** @var array<string, array{label: string, rules: list<string>}> */
    public const FIELDS = [
        'name_owner' => ['label' => 'اسم المالك', 'rules' => ['nullable', 'string', 'max:150']],
        'property_owner_id_num' => ['label' => 'رقم هوية المالك', 'rules' => ['nullable', 'regex:/^[127]\d{9}$/']],
        'property_owner_mobile' => ['label' => 'جوال المالك', 'rules' => ['nullable', 'regex:/^(00966|966|0)?5\d{8}$/']],
        'property_owner_iban' => ['label' => 'آيبان المالك', 'rules' => ['nullable', 'regex:/^SA\d{22}$/i']],
        'property_owner_dob' => ['label' => 'تاريخ ميلاد المالك', 'rules' => ['nullable', 'string', 'max:20']],
        'tenant_id_num' => ['label' => 'رقم هوية المستأجر', 'rules' => ['nullable', 'regex:/^[127]\d{9}$/']],
        'tenant_mobile' => ['label' => 'جوال المستأجر', 'rules' => ['nullable', 'regex:/^(00966|966|0)?5\d{8}$/']],
        'tenant_dob' => ['label' => 'تاريخ ميلاد المستأجر', 'rules' => ['nullable', 'string', 'max:20']],
        'tenant_entity_unified_registry_number' => ['label' => 'الرقم الموحد للمنشأة', 'rules' => ['nullable', 'regex:/^7\d{9}$/']],
        'instrument_number' => ['label' => 'رقم الصك', 'rules' => ['nullable', 'string', 'max:40']],
        'instrument_history' => ['label' => 'تاريخ الصك', 'rules' => ['nullable', 'string', 'max:20']],
        'real_estate_registry_number' => ['label' => 'رقم السجل العقاري', 'rules' => ['nullable', 'string', 'max:40']],
        'neighborhood' => ['label' => 'الحي', 'rules' => ['nullable', 'string', 'max:120']],
        'street' => ['label' => 'الشارع', 'rules' => ['nullable', 'string', 'max:120']],
        'building_number' => ['label' => 'رقم المبنى', 'rules' => ['nullable', 'string', 'max:10']],
        'postal_code' => ['label' => 'الرمز البريدي', 'rules' => ['nullable', 'regex:/^\d{5}$/']],
        'extra_figure' => ['label' => 'الرقم الإضافي', 'rules' => ['nullable', 'regex:/^\d{4}$/']],
        'unit_number' => ['label' => 'رقم الوحدة', 'rules' => ['nullable', 'string', 'max:20']],
        'floor_number' => ['label' => 'رقم الدور', 'rules' => ['nullable', 'string', 'max:10']],
        'unit_area' => ['label' => 'المساحة', 'rules' => ['nullable', 'numeric', 'min:0', 'max:1000000']],
        'electricity_meter_number' => ['label' => 'رقم عداد الكهرباء', 'rules' => ['nullable', 'string', 'max:40']],
        'water_meter_number' => ['label' => 'رقم عداد المياه', 'rules' => ['nullable', 'string', 'max:40']],
        'annual_rent_amount_for_the_unit' => ['label' => 'الإيجار السنوي', 'rules' => ['nullable', 'numeric', 'min:0', 'max:100000000']],
        'Guarantee_amount' => ['label' => 'مبلغ الضمان', 'rules' => ['nullable', 'numeric', 'min:0', 'max:100000000']],
        'deposit' => ['label' => 'العربون', 'rules' => ['nullable', 'numeric', 'min:0', 'max:100000000']],
        'daily_fine' => ['label' => 'غرامة التأخير اليومية', 'rules' => ['nullable', 'numeric', 'min:0', 'max:1000000']],
        'contract_starting_date' => ['label' => 'تاريخ بداية العقد', 'rules' => ['nullable', 'string', 'max:20']],
        'ejar_contract_draft_number' => ['label' => 'رقم مسودة إيجار', 'rules' => ['nullable', 'string', 'max:60']],
        'ejar_contract_number' => ['label' => 'رقم عقد إيجار', 'rules' => ['nullable', 'string', 'max:60']],
        'deed_number' => ['label' => 'رقم الصك الموثّق', 'rules' => ['nullable', 'string', 'max:60']],
        'notes' => ['label' => 'ملاحظات', 'rules' => ['nullable', 'string', 'max:2000']],
        // دفعة (هـ) — E5: حقول تؤثر في السعر (تُعيد حساب فرق السعر تلقائياً).
        'instrument_type' => ['label' => 'نوع المستند', 'rules' => ['nullable', 'string', 'max:80']],
        'duration_preset' => ['label' => 'مدة العقد (نمط)', 'rules' => ['nullable', 'string', 'max:30']],
        'duration_years' => ['label' => 'مدة العقد (سنوات)', 'rules' => ['nullable', 'integer', 'min:0', 'max:50']],
        'duration_months' => ['label' => 'مدة العقد (أشهر)', 'rules' => ['nullable', 'integer', 'min:0', 'max:600']],
        'total_months' => ['label' => 'إجمالي أشهر العقد', 'rules' => ['nullable', 'integer', 'min:1', 'max:600']],
        'electricity_meter_ownership' => ['label' => 'ملكية عداد الكهرباء', 'rules' => ['nullable', 'in:owner,tenant,shared']],
        'water_meter_ownership' => ['label' => 'ملكية عداد المياه', 'rules' => ['nullable', 'in:owner,tenant,shared']],
    ];

    /** الحقول التي قد تغيّر السعر (ContractPricing). */
    public const PRICE_FIELDS = ['instrument_type', 'duration_preset', 'duration_years', 'duration_months', 'total_months', 'electricity_meter_ownership', 'water_meter_ownership', 'contract_term_in_years', 'contract_type'];

    /** @var list<string> */
    public const MOBILE_FIELDS = ['property_owner_mobile', 'tenant_mobile'];

    public function __construct(private readonly OrderFlowService $flow) {}

    public static function canonicalMobile(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (str_starts_with($digits, '00966')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '966')) {
            $digits = substr($digits, 3);
        }

        return ltrim($digits, '0');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{contract_id: int, changed: array<string, array{label: string, before: mixed, after: mixed}>, price_difference: array<string, mixed>|null, payment_state: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function patch(Contract $contract, array $input, ?Employee $employee): array
    {
        $unknown = array_diff(array_keys($input), array_keys(self::FIELDS));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['fields' => ['حقول غير مسموح تعديلها من هنا: '.implode(', ', $unknown)]]);
        }
        if ($input === []) {
            throw ValidationException::withMessages(['fields' => ['لا توجد حقول للتعديل.']]);
        }

        $clean = array_map(fn ($v) => is_string($v) ? trim($this->ascii($v)) : $v, $input);
        $rules = array_map(fn ($f) => $f['rules'], array_intersect_key(self::FIELDS, $clean));
        $labels = array_map(fn ($f) => $f['label'], self::FIELDS);
        Validator::make($clean, $rules, [], $labels)->validate();

        if (array_key_exists('instrument_type', $clean) && filled($clean['instrument_type'])) {
            $normalized = Contract::normalizeInstrumentType((string) $clean['instrument_type']);
            if ($normalized === null || ! in_array($normalized, Contract::instrumentTypes(), true)) {
                throw ValidationException::withMessages(['instrument_type' => ['نوع المستند غير معروف.']]);
            }
            $clean['instrument_type'] = $normalized;
        }

        $changed = [];
        foreach ($clean as $key => $value) {
            $value = $value === '' ? null : $value;
            // متابعة دفعة (د): الجوالات بنفس صيغة المعالج 5XXXXXXXX.
            if ($value !== null && in_array($key, self::MOBILE_FIELDS, true)) {
                $value = self::canonicalMobile((string) $value);
            }
            $before = $contract->getAttribute($key);
            if ((string) ($before ?? '') === (string) ($value ?? '')) {
                continue;
            }
            $changed[$key] = ['label' => self::FIELDS[$key]['label'], 'before' => $before, 'after' => $value];
            $contract->setAttribute($key, $value);
        }

        if ($changed !== []) {
            $contract->save();
            $this->flow->activity(
                $contract, 'edited', $employee,
                array_map(fn ($c) => $c['before'], $changed),
                array_map(fn ($c) => $c['after'], $changed),
                'employee',
                'تعديل من اللوحة: '.implode('، ', array_column($changed, 'label')),
            );
        }

        // دفعة (هـ) — E5: أي تعديل يغيّر السعر ⇒ فرق سعر معلّق (أو refund_due).
        $priceDifference = null;
        if ($changed !== [] && array_intersect(array_keys($changed), self::PRICE_FIELDS) !== []) {
            $sync = app(\App\Services\Charges\ChargeService::class)->syncPriceDifference($contract, $employee, $changed);
            $priceDifference = [
                'difference' => $sync['difference'],
                'refund_due' => $sync['refund_due'],
                'reason' => $sync['reason'],
                'charge' => $sync['charge'] ? app(\App\Services\Payments\ContractPaymentState::class)->chargeArray($sync['charge'], $contract) : null,
            ];
        }

        return [
            'contract_id' => (int) $contract->id,
            'changed' => $changed,
            'price_difference' => $priceDifference,
            'payment_state' => app(\App\Services\Payments\ContractPaymentState::class)->state($contract->fresh()),
        ];
    }

    private function ascii(string $v): string
    {
        return strtr($v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }
}
