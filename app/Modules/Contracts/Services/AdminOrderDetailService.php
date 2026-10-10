<?php

namespace App\Modules\Contracts\Services;

use App\Http\Resources\Admin\V2\Api\AdminContractDetailResource;
use App\Models\Contract;
use App\Models\TenantRole;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class AdminOrderDetailService
{
    public function __construct(
        private readonly AdminOrderQueryService $orders
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fullPayload(Contract $contract, Request $request): array
    {
        $contract->load($this->orders->contractDetailRelations());
        $detail = (new AdminContractDetailResource($contract))->toArray($request);

        return array_merge(
            $detail,
            $this->buildStepBasedDetailResponse($detail),
            [
                'user_contracts' => $this->userContractSummariesForUser($contract->user_id),
            ],
            // دفعة (هـ) — 2.1/2.3/2.4/2.6: حالة الدفع، الرسوم، طلبات المرفق، العنوان/المستند/الوحدات المهيكلة، الرحلة.
            app(\App\Services\Orders\OrderDetailExtras::class)->for($contract),
            // دفعة (و) — D9: مسودة العقد المرفوعة للعميل + اختياره الدفع بعد المسودة.
            \App\Services\Orders\DraftDocumentService::customerFields($contract, staff: true),
            ['pay_after_draft_enabled' => \App\Models\Setting::payAfterDraftEnabled()],
        );
    }

    /**
     * @return array<int, array{id: int, uuid: string}>
     */
    private function userContractSummariesForUser(?int $userId): array
    {
        if (! $userId) {
            return [];
        }

        return Contract::query()
            ->where('user_id', $userId)
            ->notDeleted()
            ->reachedAdminOrderStep()
            ->orderByDesc('id')
            ->get(['id', 'uuid'])
            ->map(static fn (Contract $contract) => [
                'id' => $contract->id,
                'uuid' => (string) $contract->uuid,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function buildStepBasedDetailResponse(array $detail): array
    {
        return [
            'contract_summary' => array_merge(Arr::only($detail, [
                'id',
                'uuid',
                'employee_id',
                'employee_name',
                'contract_type',
                'instrument_type',
                'instrument_number',
                'instrument_history',
                'real_estate_registry_number',
                'deed_number',
                'is_real',
                'real_id',
                'real_units_id',
                'image_instrument',
                'image_instrument_from_the_back',
                'image_instrument_from_the_front',
                'is_multiple_trusteeship_deed_copy',
                'copy_of_the_endowment_registration_certificate',
                'copy_of_the_trusteeship_deed',
                'Image_inheritance_certificate',
                'copy_power_of_attorney_from_heirs_to_agent',
                'copy_of_guardians_power_of_attorney_for_agent',
                'is_completed',
                'is_draft',
                'status',
                'contract_period_id',
                'documentation_deadline_at',
                'name_owner',
                'type_dob_property_owner',
                'property_owner_id_num',
                'property_owner_iban',
                'property_owner_dob',
                'property_owner_dob_day',
                'property_owner_dob_month',
                'property_owner_dob_year',
                'id_num_of_property_owner_agent',
                'property_owner_mobile',
                'add_legal_agent_of_owner',
                'type_dob_property_owner_agent',
                'dob_of_property_owner_agent',
                'dob_of_property_owner_agent_day',
                'dob_of_property_owner_agent_month',
                'dob_of_property_owner_agent_year',
                'mobile_of_property_owner_agent',
                'agency_number_in_instrument_of_property_owner',
                'type_agency_instrument_date_of_property_owner',
                'agency_instrument_date_of_property_owner',
                'copy_of_the_authorization_or_agency',
            ]), [
                'contract_status_name' => Arr::get($detail, 'contract_status.name'),
                'contract_status_color' => Arr::get($detail, 'contract_status.color'),
                'contract_type' => Arr::get($detail, 'contract_type_trans', Arr::get($detail, 'contract_type')),
                ...$this->instrumentTypeSummaryFields($detail),
                'contract_type_key' => Arr::get($detail, 'contract_type_key', Arr::get($detail, 'contract_type')),
                'contract_period' => Arr::get($detail, 'contract_period.period'),
                'accept_retrun_contract' => (bool) Arr::get($detail, 'accept_retrun_contract', false),
                'accept_retrun_contract_employee_id' => Arr::get($detail, 'accept_retrun_contract_employee_id'),
                'accept_retrun_contract_employee' => Arr::get($detail, 'accept_retrun_contract_employee'),
                'return_status' => Arr::get($detail, 'return_status'),
                'return_contract' => (bool) Arr::get($detail, 'return_contract', false),
                'has_return_request' => (bool) Arr::get($detail, 'has_return_request', false),
                'return_request_status' => Arr::get($detail, 'return_request_status'),
                'refund_contract_id' => Arr::get($detail, 'refund_contract_id'),
                'draft_contract_number' => Arr::get($detail, 'draft_contract_number'),
                'refund_amount' => Arr::get($detail, 'refund_amount'),
                'received_at' => Arr::get($detail, 'received_at'),
                'received_since' => Arr::get($detail, 'received_since'),
                'received_since_label_ar' => Arr::get($detail, 'received_since_label_ar'),
                'receive_speed' => Arr::get($detail, 'receive_speed'),
                'receive_speed_label_ar' => Arr::get($detail, 'receive_speed_label_ar'),
            ]),
            'step1' => array_merge(Arr::only($detail, [
                'building_number',
                'property_place_id',
                'property_city_id',
                'neighborhood',
                'street',
                'postal_code',
                'extra_figure',
                'address_url',
                'image_address',
                'latitude',
                'longitude',
                'property_type_id',
                'property_usages_id',
                'age_of_the_property',
                'number_of_floors',
                'number_of_units_per_floor',
                'number_of_units_in_realestate',
            ]), [
                'property_place_name' => $this->relationName(Arr::get($detail, 'property_region')),
                'city_name' => $this->relationName(Arr::get($detail, 'property_city')),
                'property_type_name' => $this->relationName(Arr::get($detail, 'property_type')),
                'property_usages_name' => $this->relationName(Arr::get($detail, 'property_usages')),
            ]),
            'step2' => array_merge(Arr::only($detail, [
                'unit_type_id',
                'unit_usage_id',
                'unit_number',
                'floor_number',
                'unit_area',
                'tootal_rooms',
                'number_of_rooms',
                'The_number_of_halls',
                'number_of_councils',
                'The_number_of_kitchens',
                'The_number_of_the_toilet',
                'The_number_of_toilets',
                'window_ac',
                'number_of_unit_air_conditioners',
                'split_ac',
                'electricity_meter_number',
                'water_meter_number',
                'kitchen_tank',
                'furnished',
                'type_furnished',
                'electricity_meter',
                'water_meter',
                'electricity_meter_ownership',
                'water_meter_ownership',
                'unit',
            ]), [
                'unit_type_name' => $this->relationName(Arr::get($detail, 'unit_type')),
                'unit_usage_name' => $this->relationName(Arr::get($detail, 'unit_usage')),
            ]),
            'step3' => array_merge(Arr::only($detail, [
                'tenant_name',
                'tenant_entity',
                'type_tenant_dob',
                'tenant_id_num',
                'tenant_dob',
                'tenant_dob_day',
                'tenant_dob_month',
                'tenant_dob_year',
                'tenant_mobile',
                'tenant_email',
                'tenant_nationality',
                'tenant_work',
                'tenant_gender',
                'tenant_entity_unified_registry_number',
                'authorization_type',
                'is_there_a_legal_representative_of_the_tenant',
                'id_num_of_property_tenant_agent',
                'type_dob_tenant_agent',
                'dob_of_property_tenant_agent',
                'dob_of_property_tenant_agent_day',
                'dob_of_property_tenant_agent_month',
                'dob_of_property_tenant_agent_year',
                'mobile_of_property_tenant_agent',
                'copy_of_the_owner_record',
                'notes',
                'tenant_role_id',
                'tenant_role',
            ]), [
                'tenant_role_names' => $this->tenantRoleNames($detail),
            ]),
            'step4' => array_merge(Arr::only($detail, [
                'contract_starting_date',
                'contract_starting_date_day',
                'contract_starting_date_month',
                'contract_starting_date_year',
                'type_contract_starting_date',
                'contract_term_in_years',
                'duration_preset',
                'duration_years',
                'duration_months',
                'total_months',
                'annual_rent_amount_for_the_unit',
                'payment_type_id',
                'additional_terms',
                'text_additional_terms',
                'notes_edits',
                'tenant_roles',
                'tenant_role_ids',
                'tenant_role_id',
                'tenant_role_values',
                'conditions',
                'other_conditions',
                'other_conditions_list',
                'other_conditions_count',
                'daily_fine',
                'payment_type',
            ]), [
                'contract_term_name' => $this->relationName(Arr::get($detail, 'contract_term_in_years')),
                'payment_type_name' => $this->relationName(Arr::get($detail, 'payment_type')),
                'tenant_role_names' => $this->tenantRoleNames($detail),
            ]),
            'payment_and_admin' => Arr::only($detail, [
                'account',
                'contract_payments',
                'received_contract',
                'relation_labels',
                'created_at',
                'updated_at',
            ]),
        ];
    }

    private function relationName(mixed $relation): ?string
    {
        if (! is_array($relation)) {
            return null;
        }

        return $relation['name'] ?? $relation['name_ar'] ?? $relation['name_en'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array{
     *     instrument_type: ?string,
     *     instrument_type_key: ?string,
     *     instrument_type_trans: ?string,
     *     instrument_type_label: ?string
     * }
     */
    private function instrumentTypeSummaryFields(array $detail): array
    {
        $key = Arr::get($detail, 'instrument_type_key');
        if (! is_string($key) || trim($key) === '') {
            $fallback = Arr::get($detail, 'instrument_type');
            $key = is_string($fallback) ? $fallback : '';
        }

        $key = trim((string) $key);
        if ($key === '') {
            return [
                'instrument_type' => null,
                'instrument_type_key' => null,
                'instrument_type_trans' => null,
                'instrument_type_label' => null,
            ];
        }

        $canonical = Contract::normalizeInstrumentType($key) ?? $key;
        $label = Contract::instrumentTypeLabel($canonical, 'ar');

        return [
            'instrument_type' => $label,
            'instrument_type_key' => $canonical,
            'instrument_type_trans' => $label,
            'instrument_type_label' => $label,
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<int, string>
     */
    private function tenantRoleNames(array $detail): array
    {
        $ids = Arr::get($detail, 'tenant_role_ids', []);
        if (! is_array($ids)) {
            $ids = [];
        }

        $ids = array_values(array_unique(array_filter(array_map(static fn ($v) => (int) $v, $ids))));

        if ($ids !== []) {
            // الترتيب حسب ترتيب المعرّفات المختارة — في PHP (FIELD() خاصة بـ MySQL وتكسر sqlite محلياً).
            $byId = TenantRole::query()
                ->whereIn('id', $ids)
                ->pluck('text_of_reason', 'id');

            $names = collect($ids)
                ->map(static fn (int $id) => $byId->get($id))
                ->filter(static fn ($v) => is_string($v) && trim($v) !== '')
                ->map(static fn ($v) => trim((string) $v))
                ->values()
                ->all();

            if ($names !== []) {
                return $names;
            }
        }

        $single = Arr::get($detail, 'tenant_role.name');
        if (is_string($single) && trim($single) !== '') {
            return [trim($single)];
        }

        return [];
    }
}
