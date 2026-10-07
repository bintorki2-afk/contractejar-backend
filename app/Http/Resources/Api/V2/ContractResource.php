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
            'status_timeline' => ContractFrontendStatus::statusTimeline($this->resource),
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
}

