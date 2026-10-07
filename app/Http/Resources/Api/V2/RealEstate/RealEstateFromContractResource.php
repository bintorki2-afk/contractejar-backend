<?php

namespace App\Http\Resources\Api\V2\RealEstate;

use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contract
 */
class RealEstateFromContractResource extends JsonResource
{
    public function __construct(
        Contract $resource,
        private readonly int $userId,
        private readonly string $nameRealEstate,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->userId,
            'source_contract_id' => $this->resource->id,
            'property_owner_iban' => $this->property_owner_iban,
            'contract_type' => $this->contract_type,
            'date_first_registration' => $this->date_first_registration,
            'real_estate_registry_number' => $this->real_estate_registry_number,
            'name_real_estate' => $this->nameRealEstate,
            'name_owner' => $this->name_owner,
            'number_of_units_in_realestate' => $this->numberOfUnitsInRealestate(),
            'instrument_number' => $this->instrument_number,
            'instrument_history' => $this->instrument_history,
            'instrument_type' => $this->instrument_type,
            'property_city_id' => $this->property_city_id,
            'street' => $this->street,
            'number_of_floors' => $this->number_of_floors,
            'postal_code' => $this->postal_code,
            'extra_figure' => $this->extra_figure,
            'type_real_estate_other' => $this->type_real_estate_other,
            'property_owner_id_num' => $this->property_owner_id_num,
            'property_owner_dob_hijri' => $this->property_owner_dob,
            'property_owner_mobile' => $this->property_owner_mobile,
            'neighborhood' => $this->neighborhood,
            'property_place_id' => $this->property_place_id,
            'building_number' => $this->building_number,
            'property_type_id' => $this->property_type_id,
            'property_usages_id' => $this->property_usages_id,
            'image_instrument' => $this->image_instrument,
            'age_of_the_property' => $this->age_of_the_property,
            'number_of_units_per_floor' => $this->number_of_units_per_floor,
            'image_address' => $this->image_address,
            'address_url' => $this->address_url,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'contract_ownership' => $this->contract_ownership,
            'electricity_meter_ownership' => $this->electricity_meter_ownership,
            'water_meter_ownership' => $this->water_meter_ownership,
            'add_legal_agent_of_owner' => $this->add_legal_agent_of_owner,
            'id_num_of_property_owner_agent' => $this->id_num_of_property_owner_agent,
            'dob_of_property_owner_agent' => $this->dob_of_property_owner_agent,
            'mobile_of_property_owner_agent' => $this->mobile_of_property_owner_agent,
            'agency_number_in_instrument_of_property_owner' => $this->agency_number_in_instrument_of_property_owner,
            'agency_instrument_date_of_property_owner' => $this->agency_instrument_date_of_property_owner,
            'copy_of_the_authorization_or_agency' => $this->copy_of_the_authorization_or_agency,
            'copy_of_the_endowment_registration_certificate' => $this->copy_of_the_endowment_registration_certificate,
            'copy_of_the_trusteeship_deed' => $this->copy_of_the_trusteeship_deed,
            'type_dob_property_owner' => $this->type_dob_property_owner,
            'type_dob_property_owner_agent' => $this->type_dob_property_owner_agent,
            'type_instrument_history' => $this->type_instrument_history,
            'type_date_first_registration' => $this->type_date_first_registration,
            'type_agency_instrument_date_of_property_owner' => $this->type_agency_instrument_date_of_property_owner,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->resolve();
    }
}
