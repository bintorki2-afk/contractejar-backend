<?php

namespace App\Http\Requests\Api\V2\Contract;

use App\Http\Requests\Api\V2\BaseApiV2Request;
use App\Http\Requests\Api\V2\Concerns\ResolvesContractIdInput;
use App\Support\ContractStartingDateInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class Step6Request extends BaseApiV2Request
{
    use ResolvesContractIdInput;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->resolveContractIdInput();

        ContractStartingDateInput::prepareRequest($this);

        if (! array_key_exists('tenant_roles', $this->all())) {
            $this->merge(['tenant_roles' => false]);
        } else {
            $tr = $this->input('tenant_roles');
            if ($tr === null || $tr === '') {
                $this->merge(['tenant_roles' => false]);
            } elseif (is_string($tr)) {
                $v = strtolower(trim($tr));
                if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
                    $this->merge(['tenant_roles' => true]);
                } elseif (in_array($v, ['0', 'false', 'no', 'off'], true)) {
                    $this->merge(['tenant_roles' => false]);
                }
            }
        }

        $legacy = $this->input('tenant_role_id');
        if (($legacy !== null && $legacy !== '') && ! $this->filled('tenant_role_ids')) {
            $this->merge([
                'tenant_role_ids' => [(int) $legacy],
            ]);
        }

        if ($this->input('duration_preset') === 'other') {
            $this->merge([
                'duration_years' => (int) $this->input('duration_years', 0),
                'duration_months' => (int) $this->input('duration_months', 0),
            ]);
        }

        $this->normalizeOtherConditionsInput();
    }

    /**
     * Accept:
     * - other_conditions_list: string[]
     * - other_conditions: string (legacy) → list of one
     */
    private function normalizeOtherConditionsInput(): void
    {
        $list = [];

        if ($this->filled('other_conditions_list') && is_array($this->input('other_conditions_list'))) {
            foreach ($this->input('other_conditions_list') as $item) {
                if (! is_scalar($item)) {
                    continue;
                }
                $text = trim((string) $item);
                if ($text !== '') {
                    $list[] = $text;
                }
            }
        } elseif ($this->filled('other_conditions') && is_string($this->input('other_conditions'))) {
            $text = trim((string) $this->input('other_conditions'));
            if ($text !== '') {
                $list[] = $text;
            }
        }

        if ($list !== []) {
            $this->merge([
                'other_conditions_list' => array_values(array_unique($list)),
                'other_conditions' => $list[0],
            ]);
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $isOther = $this->input('duration_preset') === 'other';

        return [
            'id' => 'required|exists:contracts,id',
            'contract_starting_date_day' => 'nullable',
            'contract_starting_date_month' => 'nullable',
            'contract_starting_date_year' => 'nullable',
            'type_contract_starting_date' => 'nullable|in:hijri,gregorian',
            // المدة الأساسية (contract_periods) — اختيارية فقط مع «مدة أخرى»
            'contract_term_in_years' => [
                Rule::requiredIf(! $isOther),
                'nullable',
                // المدد المعروضة للعميل فقط (سنة/سنتين = is_active): منع تمرير مدة
                // غير مفعّلة بطلب مباشر يتجاوز الواجهة. (CROSS-3)
                Rule::exists('contract_periods', 'id')->where('is_active', true),
            ],
            // مسار إضافي فقط
            'duration_preset' => 'nullable|in:other',
            'duration_years' => 'nullable|integer|min:1|max:30|required_if:duration_preset,other',
            'duration_months' => 'nullable|integer|min:0|max:11|required_if:duration_preset,other',
            'annual_rent_amount_for_the_unit' => 'nullable|numeric|min:0',
            'Guarantee_amount' => 'nullable|numeric|min:0',
            'deposit' => 'nullable|numeric|min:0',
            'daily_fine' => 'nullable|numeric|min:0',
            'payment_type_id' => 'required|exists:payment_types,id',
            'conditions' => 'required|boolean',
            // Legacy single text (still accepted; converted to list in prepareForValidation).
            'other_conditions' => 'nullable|string|max:1000',
            'other_conditions_list' => [
                Rule::requiredIf(fn () => (bool) $this->input('conditions')),
                'nullable',
                'array',
                'min:1',
                'max:50',
            ],
            'other_conditions_list.*' => 'required|string|max:1000',
            'additional_terms' => 'nullable|boolean',
            'tenant_roles' => 'boolean',
            'tenant_role_id' => 'nullable|integer|exists:tenant_roles,id',
            'tenant_role_ids' => 'nullable|array',
            'tenant_role_ids.*' => 'integer|exists:tenant_roles,id',
            'tenant_role_values' => 'nullable|array',
            'tenant_role_values.*' => 'nullable',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach (ContractStartingDateInput::validationErrors($this) as $key => $msgs) {
                foreach ($msgs as $m) {
                    $v->errors()->add($key, $m);
                }
            }

            $isOther = $this->input('duration_preset') === 'other';

            if ($isOther && (int) $this->input('duration_years', 0) < 1) {
                $v->errors()->add('duration_years', 'عدد السنوات مطلوب عند اختيار مدة أخرى.');
            }

            if (! $isOther && ! $this->filled('contract_term_in_years')) {
                $v->errors()->add('contract_term_in_years', 'مدة العقد مطلوبة.');
            }

            if ((bool) $this->input('conditions')) {
                $list = $this->input('other_conditions_list', []);
                if (! is_array($list) || $list === []) {
                    $v->errors()->add('other_conditions_list', 'أضف شرطاً واحداً على الأقل في الشروط الأخرى.');
                }
            }

            $this->validateTenantRoleValues($v);
        });
    }

    private function validateTenantRoleValues(Validator $v): void
    {
        $roleIds = $this->input('tenant_role_ids', []);
        if (! is_array($roleIds) || $roleIds === []) {
            return;
        }

        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        $roles = \App\Models\TenantRole::query()->whereIn('id', $roleIds)->get()->keyBy('id');
        $values = $this->input('tenant_role_values', []);
        if (! is_array($values)) {
            $values = [];
        }

        foreach ($roleIds as $roleId) {
            $role = $roles->get($roleId);
            if (! $role || ! $role->requiresUserInput()) {
                continue;
            }

            $raw = $values[(string) $roleId] ?? $values[$roleId] ?? null;
            if ($raw === null || $raw === '') {
                $v->errors()->add(
                    "tenant_role_values.{$roleId}",
                    ($role->input_field_label ?: 'قيمة الصلاحية').' مطلوب.'
                );
                continue;
            }

            if ($role->input_field_type === \App\Models\TenantRole::INPUT_TYPE_NUMBER && ! is_numeric($raw)) {
                $v->errors()->add(
                    "tenant_role_values.{$roleId}",
                    ($role->input_field_label ?: 'قيمة الصلاحية').' يجب أن يكون رقماً.'
                );
            }
        }
    }

    public function messages(): array
    {
        return $this->contractV2ArabicMessages([
            'id',
            'contract_starting_date',
            'contract_starting_date_day',
            'contract_starting_date_month',
            'contract_starting_date_year',
            'type_contract_starting_date',
            'duration_preset',
            'duration_years',
            'duration_months',
            'contract_term_in_years',
            'annual_rent_amount_for_the_unit',
            'payment_type_id',
            'conditions',
            'other_conditions',
            'other_conditions_list',
            'additional_terms',
            'tenant_roles',
            'tenant_role_id',
            'tenant_role_ids',
        ], [
            'contract_term_in_years.required' => 'مدة العقد مطلوبة.',
            'duration_preset.in' => 'قيمة مدة أخرى غير صالحة.',
            'duration_years.required_if' => 'عدد السنوات مطلوب عند اختيار مدة أخرى.',
            'duration_months.required_if' => 'عدد الأشهر مطلوب عند اختيار مدة أخرى.',
            'other_conditions_list.required' => 'أضف شرطاً واحداً على الأقل في الشروط الأخرى.',
            'other_conditions_list.min' => 'أضف شرطاً واحداً على الأقل في الشروط الأخرى.',
            'other_conditions_list.*.required' => 'نص الشرط مطلوب.',
            'tenant_role_ids.*.exists' => 'إحدى صفات المستأجر المحددة غير موجودة.',
            'tenant_role_ids.*.integer' => 'صفة المستأجر يجب أن تكون رقماً صحيحاً.',
        ]);
    }

    /**
     * Normalized list for persistence (empty when conditions off).
     *
     * @return list<string>
     */
    public function resolvedOtherConditionsList(): array
    {
        if (! (bool) $this->input('conditions')) {
            return [];
        }

        $list = $this->input('other_conditions_list', []);
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($v) => is_scalar($v) ? trim((string) $v) : '',
            $list
        )));
    }
}
