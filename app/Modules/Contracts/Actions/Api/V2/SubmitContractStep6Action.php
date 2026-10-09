<?php

namespace App\Modules\Contracts\Actions\Api\V2;

use App\Http\Requests\Api\V2\Contract\Step6Request;
use App\Models\Contract;
use App\Models\TenantRole;
use App\Support\ContractStartingDateInput;
use App\Support\DocFee;

class SubmitContractStep6Action
{
    /**
     * @return array{ok: true, contract: Contract}|array{ok: false, message: string, code?: int}
     */
    public function execute(Contract $contract, Step6Request $request): array
    {
        if ($contract->lockedForCustomerStep(6)) {
            return ['ok' => false, 'message' => trans('api.completed_contract')];
        }

        $isOther = $request->input('duration_preset') === 'other';
        $docFee = null;

        $data = [
            'contract_starting_date' => ContractStartingDateInput::resolveForStorage($request),
            'type_contract_starting_date' => $request->input('type_contract_starting_date', 'hijri'),
            'payment_type_id' => $request->payment_type_id,
            'additional_terms' => $request->additional_terms ?? 0,
            'text_additional_terms' => $request->text_additional_terms,
            'tenant_roles' => $request->boolean('tenant_roles'),
            'step' => 7,
        ];

        if ($request->filled('annual_rent_amount_for_the_unit')) {
            $data['annual_rent_amount_for_the_unit'] = $request->annual_rent_amount_for_the_unit;
        }

        if ($isOther) {
            $years = (int) $request->input('duration_years', 0);
            $months = (int) $request->input('duration_months', 0);
            $docFee = DocFee::summarize((string) $contract->contract_type, 'other', $years, $months);

            $data['duration_preset'] = 'other';
            $data['duration_years'] = $docFee['duration_years'];
            $data['duration_months'] = $docFee['duration_months'];
            $data['total_months'] = $docFee['total_months'];
            $data['contract_term_in_years'] = $request->filled('contract_term_in_years')
                ? $request->contract_term_in_years
                : null;
        } else {
            $data['contract_term_in_years'] = $request->contract_term_in_years;
            $data['duration_preset'] = null;
            $data['duration_years'] = null;
            $data['duration_months'] = null;
            $data['total_months'] = null;
        }

        [$tenantRoleIds, $firstTenantRoleId] = $this->normalizeTenantRoleIdsFromStep6Request($request);
        $data['tenant_role_ids'] = $tenantRoleIds !== [] ? $tenantRoleIds : null;
        $data['tenant_role_id'] = $firstTenantRoleId;
        $data['tenant_role_values'] = $this->normalizeTenantRoleValuesFromStep6Request($request, $tenantRoleIds);

        $otherConditionsList = $request->resolvedOtherConditionsList();
        // متابعة دفعة (د) — QA: التطبيق يرسل conditions=false مع additional_terms + other_conditions — النص يُحفظ متى وُجد.
        if ($otherConditionsList !== []) {
            $data['other_conditions_list'] = $otherConditionsList;
            $data['other_conditions'] = $otherConditionsList[0];
        } else {
            $data['other_conditions_list'] = null;
            $data['other_conditions'] = null;
        }

        if ($request->filled('daily_fine')) {
            $data['daily_fine'] = $request->daily_fine;
        }

        // Guarantee (الضمان) and deposit were never captured before, so they never
        // reached the dashboard. Persist them here.
        if ($request->filled('Guarantee_amount')) {
            $data['Guarantee_amount'] = $request->input('Guarantee_amount');
        }

        if ($request->filled('deposit')) {
            $data['deposit'] = $request->input('deposit');
        }

        // متابعة دفعة (د) — QA: التطبيق يرسل الضمان/الغرامة/العربون كقيم صفات المستأجر فقط ⇒ نشتق الأعمدة منها
        // (قاعدة واحدة للموقع والتطبيق) عندما لا يُرسل الحقل المخصّص.
        foreach ($this->amountsFromTenantRoles($tenantRoleIds, $data['tenant_role_values'] ?? null) as $column => $value) {
            if (! array_key_exists($column, $data)) {
                $data[$column] = $value;
            }
        }

        $contract->update($data);

        return ['ok' => true, 'contract' => $contract->fresh(['realEstate', 'contractStatus', 'contractTermInYears'])];
    }

    /**
     * صفة «مبلغ الضمان» ⇒ Guarantee_amount، «غرامة يومية» ⇒ daily_fine، «عربون» ⇒ deposit (بالنص لا بالرقم).
     *
     * @param  list<int>  $roleIds
     * @param  array<string, string>|null  $values
     * @return array<string, string>
     */
    private function amountsFromTenantRoles(array $roleIds, ?array $values): array
    {
        if ($roleIds === [] || $values === null || $values === []) {
            return [];
        }

        $out = [];
        foreach (TenantRole::query()->whereIn('id', $roleIds)->get(['id', 'text_of_reason']) as $role) {
            $value = $values[(string) $role->id] ?? null;
            if ($value === null || $value === '' || ! is_numeric(strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']))) {
                continue;
            }
            $value = strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
            $text = (string) $role->text_of_reason;
            $column = match (true) {
                str_contains($text, 'ضمان') => 'Guarantee_amount',
                str_contains($text, 'غرام') => 'daily_fine',
                str_contains($text, 'عربون') => 'deposit',
                default => null,
            };
            if ($column !== null && ! isset($out[$column])) {
                $out[$column] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array{0: list<int>, 1: int|null}
     */
    private function normalizeTenantRoleIdsFromStep6Request(Step6Request $request): array
    {
        $raw = $request->input('tenant_role_ids');
        $ids = is_array($raw) ? $raw : [];

        $ids = array_values(array_unique(array_filter(array_map(static fn ($v) => (int) $v, $ids))));

        $first = $ids[0] ?? null;

        return [$ids, $first];
    }

    /**
     * @param  list<int>  $roleIds
     * @return array<string, string>|null
     */
    private function normalizeTenantRoleValuesFromStep6Request(Step6Request $request, array $roleIds): ?array
    {
        if ($roleIds === []) {
            return null;
        }

        $raw = $request->input('tenant_role_values', []);
        if (! is_array($raw)) {
            $raw = [];
        }

        $roles = TenantRole::query()->whereIn('id', $roleIds)->get()->keyBy('id');
        $normalized = [];

        foreach ($roleIds as $roleId) {
            $role = $roles->get($roleId);
            if (! $role || ! $role->requiresUserInput()) {
                continue;
            }

            $value = $raw[(string) $roleId] ?? $raw[$roleId] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $normalized[(string) $roleId] = is_scalar($value) ? (string) $value : '';
        }

        return $normalized !== [] ? $normalized : null;
    }
}
