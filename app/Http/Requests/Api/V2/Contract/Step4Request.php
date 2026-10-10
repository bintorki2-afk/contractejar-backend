<?php

namespace App\Http\Requests\Api\V2\Contract;

use App\Http\Requests\Api\V2\BaseApiV2Request;
use App\Http\Requests\Api\V2\Concerns\NormalizesSaudiMobileInputs;
use App\Http\Requests\Api\V2\Concerns\ResolvesContractIdInput;
use App\Models\Contract;
use App\Support\HijriDobParts;

class Step4Request extends BaseApiV2Request
{
    use NormalizesSaudiMobileInputs;
    use ResolvesContractIdInput;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->resolveContractIdInput();
        $this->normalizeSaudiMobileFields([
            'tenant_mobile',
            'mobile_of_property_tenant_agent',
        ]);

        foreach (['region_of_the_tenant_legal_agent', 'city_of_the_tenant_legal_agent'] as $optionalField) {
            if ($this->exists($optionalField) && trim((string) $this->input($optionalField)) === '') {
                $this->merge([$optionalField => null]);
            }
        }

        if ($this->filled('tenant_dob') && ! $this->filled('tenant_dob_day')) {
            $parts = HijriDobParts::split((string) $this->input('tenant_dob'));
            if ($parts['day'] !== null && $parts['month'] !== null && $parts['year'] !== null) {
                $this->merge([
                    'tenant_dob_day' => (int) $parts['day'],
                    'tenant_dob_month' => (int) $parts['month'],
                    'tenant_dob_year' => (int) $parts['year'],
                ]);
            }
        }

        if ($this->filled('dobof_property_tenant_agent') && ! $this->filled('dobof_property_tenant_agent_day')) {
            $parts = HijriDobParts::split((string) $this->input('dobof_property_tenant_agent'));
            if ($parts['day'] !== null && $parts['month'] !== null && $parts['year'] !== null) {
                $this->merge([
                    'dobof_property_tenant_agent_day' => (int) $parts['day'],
                    'dobof_property_tenant_agent_month' => (int) $parts['month'],
                    'dobof_property_tenant_agent_year' => (int) $parts['year'],
                ]);
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        if ($this->isLeaseRenewalContract()) {
            // QA-F C4: تجديد العقد يجمع بيانات المستأجر أيضاً (الموقع يطلبها ويعرضها في المراجعة)
            // — كانت تُتجاهل كلياً. اختيارية هنا بنفس صيغ التحقق، وتُحفظ إن وصلت.
            return [
                'id' => 'required|exists:contracts,id',
                'notes_edits' => 'nullable|string|max:20000',
                'tenant_entity' => 'nullable|in:person,institution',
                'tenant_id_num' => 'nullable|min:10|regex:/^[12]\d{9}$/',
                'tenant_dob' => 'nullable',
                'tenant_dob_day' => 'nullable',
                'tenant_dob_month' => 'nullable',
                'tenant_dob_year' => 'nullable',
                'tenant_mobile' => 'nullable|min:9|regex:/^5[0-9]{8}$/',
                'tenant_entity_unified_registry_number' => 'nullable|regex:/^7\d{9}$/',
                'type_tenant_dob' => 'nullable|in:hijri,gregorian',
                'authorization_type' => 'nullable|in:owner_and_representative_of_record,agent_for_the_tenant,agent_or_authorized_by_registry_owner',
                'id_num_of_property_tenant_agent' => 'nullable|min:10|regex:/^[12]\d{9}$/',
                'mobile_of_property_tenant_agent' => 'nullable|min:9|regex:/^5[0-9]{8}$/',
                'dobof_property_tenant_agent_day' => 'nullable',
                'dobof_property_tenant_agent_month' => 'nullable',
                'dobof_property_tenant_agent_year' => 'nullable',
                'type_dob_tenant_agent' => 'nullable|in:hijri,gregorian',
            ];
        }

        return [
            'id' => 'required|exists:contracts,id',
            'tenant_entity' => 'required|in:person,institution',
            'tenant_id_num' => 'nullable|required_if:tenant_entity,person|min:10|regex:/^[12]\d{9}$/',
            'tenant_dob' => 'nullable',
            'tenant_dob_day' => 'nullable|required_if:tenant_entity,person',
            'tenant_dob_month' => 'nullable|required_if:tenant_entity,person',
            'tenant_dob_year' => 'nullable|required_if:tenant_entity,person',
            'tenant_mobile' => 'nullable|required_if:tenant_entity,person|min:9|regex:/^5[0-9]{8}$/',
            'region_of_the_tenant_legal_agent' => 'nullable|exists:regions,id',
            'city_of_the_tenant_legal_agent' => 'nullable|exists:cities,id',
            'tenant_entity_unified_registry_number' => 'nullable|required_if:tenant_entity,institution|regex:/^7\d{9}$/',
            'authorization_type' => 'nullable|required_if:tenant_entity,institution|in:owner_and_representative_of_record,agent_for_the_tenant,agent_or_authorized_by_registry_owner',
            'copy_of_the_owner_record' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            // Tenant-entity representative authorization (same key the website / v1 API / dashboard use).
            'copy_of_the_authorization_or_agency' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'id_num_of_property_tenant_agent' => 'nullable|min:10|regex:/^[12]\d{9}$/',
            'mobile_of_property_tenant_agent' => 'nullable|min:9|regex:/^5[0-9]{8}$/',
            'dobof_property_tenant_agent_day' => 'nullable',
            'dobof_property_tenant_agent_month' => 'nullable',
            'dobof_property_tenant_agent_year' => 'nullable',
            'type_tenant_dob' => 'nullable|in:hijri,gregorian',
            'type_dob_tenant_agent' => 'nullable|in:hijri,gregorian',
            'notes_edits' => 'nullable|string|max:20000',

        ];
    }

    private function isLeaseRenewalContract(): bool
    {
        $contractId = $this->input('id');

        return $contractId
            && Contract::query()->whereKey($contractId)->value('instrument_type') === 'lease_renewal';
    }

    public function messages(): array
    {
        return $this->contractV2ArabicMessages([
            'id',
            'notes_edits',
            'tenant_entity',
            'tenant_id_num',
            'tenant_dob',
            'tenant_dob_day',
            'tenant_dob_month',
            'tenant_dob_year',
            'tenant_mobile',
            'region_of_the_tenant_legal_agent',
            'city_of_the_tenant_legal_agent',
            'tenant_entity_unified_registry_number',
            'authorization_type',
            'copy_of_the_owner_record',
            'id_num_of_property_tenant_agent',
            'mobile_of_property_tenant_agent',
            'dobof_property_tenant_agent',
            'dobof_property_tenant_agent_day',
            'dobof_property_tenant_agent_month',
            'dobof_property_tenant_agent_year',
            'type_tenant_dob',
            'type_dob_tenant_agent',
        ]);
    }
}

