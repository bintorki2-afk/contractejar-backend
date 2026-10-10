<?php

namespace App\Modules\Contracts\Actions\Api\V2;

use App\Http\Requests\Api\V2\Contract\Step4Request;
use App\Models\Contract;
use App\Support\HijriDobParts;

class SubmitContractStep4Action
{
    /**
     * @return array{ok: true, contract: Contract}|array{ok: false, message: string, code?: int}
     */
    public function execute(Contract $contract, Step4Request $request): array
    {
        if ($contract->instrument_type === 'lease_renewal') {
            if ($contract->lockedForCustomerStep(4)) {
                return ['ok' => false, 'message' => trans('api.completed_contract')];
            }

            $leaseRenewalData = ['step' => 5];
            if ($request->has('notes_edits')) {
                $leaseRenewalData['notes_edits'] = $request->input('notes_edits');
            }

            $contract->update($leaseRenewalData);

            return ['ok' => true, 'contract' => $contract->fresh(['contractStatus'])];
        }

        $validatedData = $request->validated();

        $tenantDobCombined = (
            $request->filled('tenant_dob_day')
            && $request->filled('tenant_dob_month')
            && $request->filled('tenant_dob_year')
        )
            ? HijriDobParts::combine(
                $request->input('tenant_dob_day'),
                $request->input('tenant_dob_month'),
                $request->input('tenant_dob_year')
            )
            : null;

        $tenantAgentDobCombined = (
            $request->filled('dobof_property_tenant_agent_day')
            && $request->filled('dobof_property_tenant_agent_month')
            && $request->filled('dobof_property_tenant_agent_year')
        )
            ? HijriDobParts::combine(
                $request->input('dobof_property_tenant_agent_day'),
                $request->input('dobof_property_tenant_agent_month'),
                $request->input('dobof_property_tenant_agent_year')
            )
            : null;

        unset(
            $validatedData['tenant_dob'],
            $validatedData['tenant_dob_day'],
            $validatedData['tenant_dob_month'],
            $validatedData['tenant_dob_year'],
            $validatedData['dobof_property_tenant_agent_day'],
            $validatedData['dobof_property_tenant_agent_month'],
            $validatedData['dobof_property_tenant_agent_year']
        );

        if ($request->hasFile('copy_of_the_owner_record')) {
            $validatedData['copy_of_the_owner_record'] = $request->file('copy_of_the_owner_record')->store('copy_of_the_owner_record', 'public');
        }

        // Tenant-entity representative authorization (website sends it with authorization_type
        // agent_or_authorized_by_registry_owner). Mirrors the v1 Step4 action.
        unset($validatedData['copy_of_the_authorization_or_agency']);
        if ($request->hasFile('copy_of_the_authorization_or_agency')) {
            $tenantAuthPath = $request->file('copy_of_the_authorization_or_agency')->store('authorizations', 'public');

            // `copy_of_the_authorization_or_agency` is ONE column shared by the owner's agent (step 3)
            // and the tenant entity's representative (step 4). Never clobber an owner-agent /
            // heirs' agent / waqf trustee document: in that case keep the tenant document in
            // `copy_of_the_owner_record` (the tenant-side document column the mobile app uses).
            $ownerAgentDocPresent = $this->truthy($contract->add_legal_agent_of_owner)
                && ! empty($contract->getAttributes()['copy_of_the_authorization_or_agency'] ?? null);

            if (! $ownerAgentDocPresent) {
                $validatedData['copy_of_the_authorization_or_agency'] = $tenantAuthPath;
            } elseif (! isset($validatedData['copy_of_the_owner_record'])) {
                $validatedData['copy_of_the_owner_record'] = $tenantAuthPath;
            }
        }

        $data = array_merge($validatedData, [
            'step' => 5,
            'tenant_dob' => $tenantDobCombined,
            'dob_of_property_tenant_agent' => $tenantAgentDobCombined,
            'type_tenant_dob' => $request->input('type_tenant_dob', 'hijri'),
            'type_dob_tenant_agent' => $request->input('type_dob_tenant_agent', 'hijri'),
            'copy_of_the_owner_record' => $validatedData['copy_of_the_owner_record'] ?? $contract->copy_of_the_owner_record,
        ]);

        $contract->update($data);

        return ['ok' => true, 'contract' => $contract->fresh(['contractStatus'])];
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
