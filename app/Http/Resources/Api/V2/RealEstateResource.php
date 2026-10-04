<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RealEstateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_real_estate' => $this->name_real_estate,
            'name_owner' => $this->name_owner,
            'contract_type' => $this->contract_type,
            'contract_ownership' => $this->contract_ownership,
            'electricity_meter_ownership' => $this->electricity_meter_ownership,
            'water_meter_ownership' => $this->water_meter_ownership,
            'instrument_type' => $this->instrument_type,
            'instrument_number' => $this->instrument_number,
            'instrument_history' => $this->instrument_history,
            'real_estate_registry_number' => $this->real_estate_registry_number,
            'date_first_registration' => $this->date_first_registration,
            'number_of_units_in_realestate' => $this->number_of_units_in_realestate,
            'number_of_floors' => $this->number_of_floors,
            'property_type_id' => $this->property_type_id,
            'property_type_name' => optional($this->propertyType)->name_trans
                ?? optional($this->propertyType)->name_ar,
            'property_usages_id' => $this->property_usages_id,
            'property_usages_name' => optional($this->propertyUsages)->name_trans
                ?? optional($this->propertyUsages)->name_ar,
            'property_city_id' => $this->property_city_id,
            'property_place_id' => $this->property_place_id,
            'property_city_name' => optional($this->tenantEntityCity)->name_trans,
            'property_place_name' => optional($this->tenantEntityRegion)->name_trans,
            'image_instrument' => \App\Support\RealEstateImage::signedUrl($this->resource, 'image_instrument'),
            'age_of_the_property' => $this->age_of_the_property,
            'number_of_units_per_floor' => $this->number_of_units_per_floor,
            'image_address' => \App\Support\RealEstateImage::signedUrl($this->resource, 'image_address'),
            'address_url' => $this->address_url,
            'latitude' => $lat = $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $lng = $this->longitude !== null ? (float) $this->longitude : null,
            'lat' => $lat,
            'lng' => $lng,
            'step' => $this->step,
        ];
    }
}

