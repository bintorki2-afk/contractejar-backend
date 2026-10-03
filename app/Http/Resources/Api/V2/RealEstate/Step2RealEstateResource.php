<?php

namespace App\Http\Resources\Api\V2\RealEstate;

use App\Support\DateInputNormalizer;
use App\Support\HijriDobParts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Step 2 response: step 1 + location + owner (formerly step 3).
 */
class Step2RealEstateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ownerDob = HijriDobParts::split($this->property_owner_dob_hijri);
        $agentDob = HijriDobParts::split($this->dob_of_property_owner_agent);
        $agencyDate = DateInputNormalizer::splitMysqlDate($this->agency_instrument_date_of_property_owner);

        return array_merge(
            (new Step1RealEstateResource($this))->toArray($request),
            [
                'name_real_estate' => $this->name_real_estate,
                'name_owner' => $this->name_owner,
                'property_owner_id_num' => $this->property_owner_id_num,
                'property_owner_dob' => $this->property_owner_dob_hijri,
                'type_dob_property_owner' => $this->type_dob_property_owner,
                'property_owner_dob_day' => $ownerDob['day'],
                'property_owner_dob_month' => $ownerDob['month'],
                'property_owner_dob_year' => $ownerDob['year'],
                'property_owner_mobile' => $this->property_owner_mobile,
                'property_owner_iban' => $this->property_owner_iban,
                'add_legal_agent_of_owner' => $this->add_legal_agent_of_owner,
                'legal_agent' => $this->legalAgentPayload($agentDob),
                'id_num_of_property_owner_agent' => $this->id_num_of_property_owner_agent,
                'dob_of_property_owner_agent' => $this->dob_of_property_owner_agent,
                'type_dob_property_owner_agent' => $this->type_dob_property_owner_agent,
                'dob_of_property_owner_agent_day' => $agentDob['day'],
                'dob_of_property_owner_agent_month' => $agentDob['month'],
                'dob_of_property_owner_agent_year' => $agentDob['year'],
                'mobile_of_property_owner_agent' => $this->mobile_of_property_owner_agent,
                'agency_number_in_instrument_of_property_owner' => $this->agency_number_in_instrument_of_property_owner,
                'agency_instrument_date_of_property_owner' => $this->agency_instrument_date_of_property_owner,
                'type_agency_instrument_date_of_property_owner' => $this->type_agency_instrument_date_of_property_owner ?? 'hijri',
                'agency_instrument_date_of_property_owner_day' => $agencyDate['day'],
                'agency_instrument_date_of_property_owner_month' => $agencyDate['month'],
                'agency_instrument_date_of_property_owner_year' => $agencyDate['year'],
                'copy_of_the_authorization_or_agency' => $this->authorizationOrAgencyUrl(),
                'step' => $this->step,
            ]
        );
    }

    /**
     * @param  array{day: int|null, month: int|null, year: int|null}  $agentDob
     * @return array<string, mixed>|null
     */
    private function legalAgentPayload(array $agentDob): ?array
    {
        if (! $this->hasLegalAgent()) {
            return null;
        }

        return [
            'id_num_of_property_owner_agent' => $this->id_num_of_property_owner_agent,
            'type_dob_property_owner_agent' => $this->type_dob_property_owner_agent ?? 'hijri',
            'dob_of_property_owner_agent' => $this->dob_of_property_owner_agent,
            'dob_of_property_owner_agent_day' => $agentDob['day'],
            'dob_of_property_owner_agent_month' => $agentDob['month'],
            'dob_of_property_owner_agent_year' => $agentDob['year'],
            'mobile_of_property_owner_agent' => $this->mobile_of_property_owner_agent,
            'copy_of_the_authorization_or_agency' => $this->authorizationOrAgencyUrl(),
        ];
    }

    private function hasLegalAgent(): bool
    {
        $add = $this->add_legal_agent_of_owner;

        return in_array((string) $add, ['1', 'true'], true)
            || $add === 1
            || $add === true;
    }

    private function authorizationOrAgencyUrl(): ?string
    {
        // Private disk: expose only a temporary signed URL (see App\Support\RealEstateImage).
        $realEstate = $this->resource;
        while ($realEstate instanceof JsonResource) {
            $realEstate = $realEstate->resource;
        }

        return \App\Support\RealEstateImage::signedUrl($realEstate, 'copy_of_the_authorization_or_agency');
    }
}
