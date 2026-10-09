<?php

namespace App\Http\Resources\Api\V2;

use App\Http\Resources\Api\V2\Contract\Concerns\MapsContractStatusFields;
use App\Http\Resources\Api\V2\UnitResource;
use App\Http\Resources\Concerns\WithContractDocumentationDeadline;
use App\Models\Contract;
use App\Support\ContractFrontendStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractResource extends JsonResource
{
    use MapsContractStatusFields;
    use WithContractDocumentationDeadline;

    public function toArray(Request $request): array
    {
        return $this->withDocumentationDeadline([
            'id' => $this->id,
            'uuid' => $this->uuid,
            'smart_link' => \App\Support\SmartLink::for($this->resource),
            'contract_type' => $this->contract_type,
            'contract_ownership' => $this->contract_ownership,
            'duration_preset' => $this->duration_preset,
            'duration_years' => $this->duration_years,
            'duration_months' => $this->duration_months,
            'total_months' => $this->total_months,
            'name_real_estate' => $this->name_real_estate,
            'property_owner_id_num' => $this->property_owner_id_num,
            'tenant_id_num' => $this->tenant_id_num,
            'instrument_type' => $this->instrument_type,
            ...Contract::instrumentTypeImageRequirements($this->instrument_type),
            // دفعة (هـ) — E4 (A-1): حقول الخطوات 1/2/3/4 للتعبئة المسبقة في وضع التصحيح (بيانات العميل نفسه).
            ...$this->fixModeScalarFields(),
            'image_instrument' => \App\Support\DeedImage::signedUrl($this->resource, 'image_instrument'),
            'image_instrument_pages' => \App\Support\DeedImage::signedPageUrls($this->resource),
            'age_of_the_property' => $this->age_of_the_property,
            'number_of_units_per_floor' => $this->number_of_units_per_floor,
            'image_address' => \App\Support\DeedImage::signedUrl($this->resource, 'image_address'),
            'address_url' => $this->address_url,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'image_instrument_from_the_front' => \App\Support\DeedImage::signedUrl($this->resource, 'image_instrument_from_the_front'),
            'image_instrument_from_the_back' => \App\Support\DeedImage::signedUrl($this->resource, 'image_instrument_from_the_back'),
            'Image_from_the_agency' => $this->Image_from_the_agency,
            'copy_power_of_attorney_from_heirs_to_agent' => \App\Support\DeedImage::signedUrl($this->resource, 'copy_power_of_attorney_from_heirs_to_agent'),
            'Image_inheritance_certificate' => \App\Support\DeedImage::signedUrl($this->resource, 'Image_inheritance_certificate'),
            'tenant_roles' => (bool) $this->tenant_roles,
            'tenant_role_ids' => $this->tenant_role_ids ?? [],
            'tenant_role_id' => $this->tenant_role_id,
            'tenant_role_values' => $this->tenant_role_values ?? [],
            'additional_terms' => (bool) $this->additional_terms,
            'text_additional_terms' => $this->text_additional_terms,
            'notes_edits' => $this->notes_edits,
            'is_completed' => (bool) $this->is_completed,
            'is_draft' => (bool) $this->is_draft,
            'step' => $this->step,
            ...$this->contractStatusFields('قيد المراجعة'),
            'contract_status_color' => optional($this->contractStatus)->color ?? '#000000',
            'contract_status_icon' => optional($this->contractStatus)->icon ?? '<i class="fa fa-check"></i>',
            'draft_contract_status_id' => $this->draft_contract_status_id,
            'draft_contract_status_name' => optional($this->draftContractStatus)->name,
            'draft_contract_status_color' => optional($this->draftContractStatus)->color,
            'journey' => ContractFrontendStatus::journey($this->resource),
            // دفعة (هـ) — E3: الحالة الجانبية (ملغي/مسترجع) تُعرض بدل الخطوات.
            'journey_side_state' => \App\Support\ContractJourney::sideState($this->resource),
            'journey_sentence' => \App\Support\ContractJourney::RULE_SENTENCE,
            'status_timeline' => ContractFrontendStatus::statusTimeline($this->resource),
            // دفعة (هـ) — 2.1/2.3/2.4: حالة الدفع والتفاصيل والرسوم وطلبات المرفق الناقص.
            'payment_state' => ($paymentState = app(\App\Services\Payments\ContractPaymentState::class))->state($this->resource),
            'payment_details' => $paymentState->details($this->resource),
            'charges' => app(\App\Services\Charges\ChargeService::class)->forCustomer($this->resource),
            'pending_data_requests' => app(\App\Services\DataRequests\ContractDataRequestService::class)->pendingForCustomer($this->resource),
            // دفعة (د) — ب9: ما تم على طلبك (نسخة آمنة: بلا أسماء موظفين أو ملاحظات داخلية).
            'activities' => app(\App\Services\Orders\ContractActivityLogger::class)->forCustomer($this->resource),
            // متابعة دفعة (د): مبلغ الاسترجاع للعميل.
            'refund' => $refund = \App\Services\Payments\PaymentRefundService::summaryFor($this->resource),
            'refunded_amount' => $refund['amount'],
            'journey_status' => ContractFrontendStatus::journeyStatus($this->resource),
            'journey_status_label' => ContractFrontendStatus::journeyStatusLabel($this->resource),
            'number_of_units_in_realestate' => $this->numberOfUnitsInRealestate(),
            'units' => $this->when(
                $this->relationLoaded('units'),
                fn () => UnitResource::collection($this->units)
            ),
            'units_count' => $this->when(
                $this->relationLoaded('units'),
                fn () => $this->units->count()
            ),
            'created_at' => optional($this->created_at)->format('Y-m-d'),
        ]);
    }

    /**
     * الحقول العددية/النصية للخطوات 1 (الصك والعقار) و2 (العنوان) و3 (المالك) و4 (المستأجر) — نفس المجموعة الآمنة
     * الموجودة في تفاصيل الطلب باللوحة، بلا أسماء أو ملفات (الملفات روابط موقّعة أعلاه).
     *
     * @return array<string, mixed>
     */
    private function fixModeScalarFields(): array
    {
        $c = $this->resource;
        $ownerDob = \App\Support\HijriDobParts::split($c->property_owner_dob);
        $tenantDob = \App\Support\HijriDobParts::split($c->tenant_dob);
        $agentDob = \App\Support\HijriDobParts::split($c->dob_of_property_owner_agent);
        $tenantAgentDob = \App\Support\HijriDobParts::split($c->dob_of_property_tenant_agent);

        return [
            // الخطوة 1
            'instrument_number' => $c->instrument_number,
            'instrument_history' => $c->instrument_history,
            'type_instrument_history' => $c->type_instrument_history,
            'real_estate_registry_number' => $c->real_estate_registry_number,
            'date_first_registration' => $c->date_first_registration,
            'type_date_first_registration' => $c->type_date_first_registration,
            'property_type_id' => $c->property_type_id,
            'property_usages_id' => $c->property_usages_id,
            'number_of_floors' => $c->number_of_floors,
            'is_multiple_trusteeship_deed_copy' => (bool) $c->is_multiple_trusteeship_deed_copy,
            'copy_of_the_endowment_registration_certificate' => \App\Support\DeedImage::signedUrl($c, 'copy_of_the_endowment_registration_certificate'),
            'copy_of_the_trusteeship_deed' => \App\Support\DeedImage::signedUrl($c, 'copy_of_the_trusteeship_deed'),
            'copy_of_guardians_power_of_attorney_for_agent' => \App\Support\DeedImage::signedUrl($c, 'copy_of_guardians_power_of_attorney_for_agent'),
            // الخطوة 2
            'property_place_id' => $c->property_place_id,
            'property_city_id' => $c->property_city_id,
            'neighborhood' => $c->neighborhood,
            'street' => $c->street,
            'building_number' => $c->building_number,
            'postal_code' => $c->postal_code,
            'extra_figure' => $c->extra_figure,
            // الخطوة 3 — المالك (بلا اسم)
            'property_owner_dob' => $c->property_owner_dob,
            'property_owner_dob_day' => $ownerDob['day'] ?? null,
            'property_owner_dob_month' => $ownerDob['month'] ?? null,
            'property_owner_dob_year' => $ownerDob['year'] ?? null,
            'type_dob_property_owner' => $c->type_dob_property_owner,
            'property_owner_mobile' => $c->property_owner_mobile,
            'property_owner_iban' => $c->property_owner_iban,
            'add_legal_agent_of_owner' => (bool) $c->add_legal_agent_of_owner,
            'id_num_of_property_owner_agent' => $c->id_num_of_property_owner_agent,
            'dob_of_property_owner_agent' => $c->dob_of_property_owner_agent,
            'dob_of_property_owner_agent_day' => $agentDob['day'] ?? null,
            'dob_of_property_owner_agent_month' => $agentDob['month'] ?? null,
            'dob_of_property_owner_agent_year' => $agentDob['year'] ?? null,
            'type_dob_property_owner_agent' => $c->type_dob_property_owner_agent,
            'mobile_of_property_owner_agent' => $c->mobile_of_property_owner_agent,
            'agency_number_in_instrument_of_property_owner' => $c->agency_number_in_instrument_of_property_owner,
            'agency_instrument_date_of_property_owner' => $c->agency_instrument_date_of_property_owner,
            'type_agency_instrument_date_of_property_owner' => $c->type_agency_instrument_date_of_property_owner,
            'copy_of_the_authorization_or_agency' => $this->publicOrSignedUrl($c, 'copy_of_the_authorization_or_agency'),
            // الخطوة 4 — المستأجر (بلا اسم)
            'tenant_entity' => $c->tenant_entity,
            'tenant_dob' => $c->tenant_dob,
            'tenant_dob_day' => $tenantDob['day'] ?? null,
            'tenant_dob_month' => $tenantDob['month'] ?? null,
            'tenant_dob_year' => $tenantDob['year'] ?? null,
            'type_tenant_dob' => $c->type_tenant_dob,
            'tenant_mobile' => $c->tenant_mobile,
            'tenant_entity_unified_registry_number' => $c->tenant_entity_unified_registry_number,
            'authorization_type' => $c->authorization_type,
            'is_there_a_legal_representative_of_the_tenant' => (bool) $c->is_there_a_legal_representative_of_the_tenant,
            'id_num_of_property_tenant_agent' => $c->id_num_of_property_tenant_agent,
            'dob_of_property_tenant_agent' => $c->dob_of_property_tenant_agent,
            'dob_of_property_tenant_agent_day' => $tenantAgentDob['day'] ?? null,
            'dob_of_property_tenant_agent_month' => $tenantAgentDob['month'] ?? null,
            'dob_of_property_tenant_agent_year' => $tenantAgentDob['year'] ?? null,
            'type_dob_tenant_agent' => $c->type_dob_tenant_agent,
            'mobile_of_property_tenant_agent' => $c->mobile_of_property_tenant_agent,
            'copy_of_the_owner_record' => $this->publicOrSignedUrl($c, 'copy_of_the_owner_record'),
        ];
    }

    private function publicOrSignedUrl(Contract $c, string $field): ?string
    {
        if (\App\Support\DeedImage::isField($field)) {
            return \App\Support\DeedImage::signedUrl($c, $field);
        }
        $raw = $c->getAttributes()[$field] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $path = ltrim(trim($raw), '/');
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return url('storage/'.(str_starts_with($path, 'storage/') ? substr($path, 8) : $path));
    }
}

