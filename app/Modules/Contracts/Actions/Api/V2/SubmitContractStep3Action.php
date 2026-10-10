<?php

namespace App\Modules\Contracts\Actions\Api\V2;

use App\Http\Requests\Api\V2\Contract\Step3Request;
use App\Models\Contract;
use App\Models\RealEstate;
use App\Support\DateInputNormalizer;
use App\Support\HijriDobParts;

class SubmitContractStep3Action
{
    /**
     * @return array{ok: true, contract: Contract}|array{ok: false, message: string, code?: int}
     */
    public function execute(Contract $contract, Step3Request $request): array
    {
        if ($contract->lockedForCustomerStep(3)) {
            return ['ok' => false, 'message' => trans('api.completed_contract')];
        }

        $data = $this->buildStep3BaseData($request, $contract);

        $shouldApplyAgentBlock = $contract->instrument_type !== 'lease_renewal'
            || $request->has('add_legal_agent_of_owner');

        // QA-F C10: تصحيح خطوة المؤجر على طلب مدفوع من إصدار تطبيق لا يعيد تعبئة الوكيل يرسل
        // add_legal_agent_of_owner=false بلا أي بيانات وكيل ⇒ كان يصفّر ٨ حقول. لا نمحو وكيلاً
        // محفوظاً على طلب مدفوع ما لم تصل بيانات وكيل بديلة (التعديل الفعلي من اللوحة).
        $existingAgent = in_array($contract->add_legal_agent_of_owner, [true, 1, '1', 'true'], true);
        if ($shouldApplyAgentBlock && (bool) $contract->is_completed && $existingAgent
            && ! $this->hasOwnerAgent($request) && ! $request->filled('id_num_of_property_owner_agent')) {
            $shouldApplyAgentBlock = false;
        }

        if ($shouldApplyAgentBlock) {
            $data = $this->hasOwnerAgent($request)
                ? $this->appendStep3AgentData($data, $request, $contract)
                : $this->appendStep3NoAgentData($data);
        }

        // #30: the website flags the deceased-owner path explicitly (property_owner_is_deceased=1);
        // otherwise derive it from the instrument type so the dashboard "متوفى" indicator is reliable.
        if ($request->has('property_owner_is_deceased')) {
            $data['property_owner_is_deceased'] = $request->boolean('property_owner_is_deceased');
        } elseif (in_array((string) $contract->instrument_type, [
            'property_ownership_owner_are_deceased',
            'property_ownership_owner_are_deceased_endowment',
        ], true)) {
            $data['property_owner_is_deceased'] = true;
        }

        $contract->update($data);
        $this->syncStep3RealEstateName($contract, $request);

        return ['ok' => true, 'contract' => $contract->fresh(['contractStatus'])];
    }

    private function buildStep3BaseData(Step3Request $request, Contract $contract): array
    {
        $dob = $request->resolvedPropertyOwnerDobString();
        $ownerDobTypeNorm = $this->normalizeOwnerCalendarType(
            $request->input('type_dob_property_owner', $request->input('type_dob'))
        );

        $typePayload = [
            'type_dob_property_owner' => $ownerDobTypeNorm,
            'type_dob' => $ownerDobTypeNorm,
        ];

        $dobPayload = [
            'property_owner_dob' => $dob,
        ];

        if ($contract->instrument_type === 'lease_renewal') {
            $data = array_merge([
                'step' => 5,
            ], $typePayload, $dobPayload);
            if ($request->filled('name_owner')) {
                $data['name_owner'] = $request->name_owner;
            }
            if ($request->filled('property_owner_id_num')) {
                $data['property_owner_id_num'] = $request->property_owner_id_num;
            }
            if ($request->filled('property_owner_mobile')) {
                $data['property_owner_mobile'] = $request->property_owner_mobile;
            }
            if ($request->has('property_owner_iban')) {
                $data['property_owner_iban'] = $request->property_owner_iban;
            }
            if ($request->has('add_legal_agent_of_owner')) {
                $data['add_legal_agent_of_owner'] = $request->input('add_legal_agent_of_owner');
            }

            return $data;
        }

        return array_merge([
            'name_owner' => $request->name_owner,
            'property_owner_id_num' => $request->property_owner_id_num,
            'property_owner_mobile' => $request->property_owner_mobile,
            'property_owner_iban' => $request->property_owner_iban,
            'add_legal_agent_of_owner' => $request->add_legal_agent_of_owner,
            'step' => 4,
        ], $typePayload, $dobPayload);
    }

    private function normalizeOwnerCalendarType(mixed $value): string
    {
        $raw = strtolower(trim((string) ($value ?? 'hijri')));

        return in_array($raw, ['hijri', 'gregorian'], true) ? $raw : 'hijri';
    }

    private function hasOwnerAgent(Step3Request $request): bool
    {
        $add = $request->add_legal_agent_of_owner;

        return in_array((string) $add, ['1', 'true'], true)
            || $add === 1
            || $add === true;
    }

    private function appendStep3AgentData(array $data, Step3Request $request, Contract $contract): array
    {
        $data['id_num_of_property_owner_agent'] = $request->id_num_of_property_owner_agent;
        $data['type_dob_property_owner_agent'] = $request->input('type_dob_property_owner_agent', 'hijri');
        $data['dob_of_property_owner_agent'] = HijriDobParts::combine(
            $request->input('dob_of_property_owner_agent_day'),
            $request->input('dob_of_property_owner_agent_month'),
            $request->input('dob_of_property_owner_agent_year')
        );
        $data['mobile_of_property_owner_agent'] = $request->mobile_of_property_owner_agent;
        $data['agency_number_in_instrument_of_property_owner'] = $request->agency_number_in_instrument_of_property_owner;
        $data['type_agency_instrument_date_of_property_owner'] = $request->input(
            'type_agency_instrument_date_of_property_owner',
            'hijri'
        );
        $data['agency_instrument_date_of_property_owner'] = DateInputNormalizer::combineFromParts(
            $request->input('agency_instrument_date_of_property_owner_day'),
            $request->input('agency_instrument_date_of_property_owner_month'),
            $request->input('agency_instrument_date_of_property_owner_year')
        );

        $data['copy_of_the_authorization_or_agency'] = $request->hasFile('copy_of_the_authorization_or_agency')
            ? $request->file('copy_of_the_authorization_or_agency')->store('authorizations', 'public')
            : $contract->copy_of_the_authorization_or_agency;

        return $data;
    }

    private function appendStep3NoAgentData(array $data): array
    {
        $data['id_num_of_property_owner_agent'] = null;
        $data['type_dob_property_owner_agent'] = null;
        $data['dob_of_property_owner_agent'] = null;
        $data['mobile_of_property_owner_agent'] = null;
        $data['agency_number_in_instrument_of_property_owner'] = null;
        $data['agency_instrument_date_of_property_owner'] = null;
        $data['type_agency_instrument_date_of_property_owner'] = null;
        $data['copy_of_the_authorization_or_agency'] = null;

        return $data;
    }

    private function syncStep3RealEstateName(Contract $contract, Step3Request $request): void
    {
        if (! $contract->real_id) {
            return;
        }

        if ($contract->instrument_type === 'lease_renewal' && ! $request->filled('name_real_estate')) {
            return;
        }

        RealEstate::query()
            ->whereKey($contract->real_id)
            ->where('user_id', $contract->user_id)
            ->update([
            'name_real_estate' => $request->name_real_estate,
        ]);
    }
}
