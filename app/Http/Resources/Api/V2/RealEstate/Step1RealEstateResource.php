<?php

namespace App\Http\Resources\Api\V2\RealEstate;

use App\Support\DateInputNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class Step1RealEstateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $firstRegParts = DateInputNormalizer::splitMysqlDate($this->formatMysqlDate($this->date_first_registration));
        $historyParts = DateInputNormalizer::splitMysqlDate($this->formatMysqlDate($this->instrument_history));

        // When nested (e.g. from Step2RealEstateResource) the wrapped resource may itself
        // be a JsonResource; unwrap to the underlying RealEstate model for signed URLs.
        $realEstate = $this->resource;
        while ($realEstate instanceof JsonResource) {
            $realEstate = $realEstate->resource;
        }

        return [
            'id' => $this->id,
            'contract_ownership' => $this->contract_ownership,
            'electricity_meter_ownership' => $this->electricity_meter_ownership,
            'water_meter_ownership' => $this->water_meter_ownership,
            'contract_type' => $this->contract_type,
            'instrument_type' => $this->instrument_type,
            'property_type_id' => $this->property_type_id,
            'property_type_name' => optional($this->propertyType)->name_trans
                ?? optional($this->propertyType)->name_ar,
            'property_usages_id' => $this->property_usages_id,
            'property_usages_name' => optional($this->propertyUsages)->name_trans
                ?? optional($this->propertyUsages)->name_ar,
            'number_of_floors' => $this->number_of_floors,
            'number_of_units_in_realestate' => $this->number_of_units_in_realestate,
            'property_owner_is_deceased' => $this->property_owner_is_deceased,
            'instrument_number' => $this->instrument_number,
            'instrument_history' => $this->formatMysqlDate($this->instrument_history),
            'instrument_history_day' => $historyParts['day'],
            'instrument_history_month' => $historyParts['month'],
            'instrument_history_year' => $historyParts['year'],
            'type_instrument_history' => $this->type_instrument_history ?? 'hijri',
            'real_estate_registry_number' => $this->real_estate_registry_number,
            'date_first_registration' => $this->formatMysqlDate($this->date_first_registration),
            'date_first_registration_day' => $firstRegParts['day'],
            'date_first_registration_month' => $firstRegParts['month'],
            'date_first_registration_year' => $firstRegParts['year'],
            'type_date_first_registration' => $this->type_date_first_registration ?? 'hijri',
            'name_real_estate' => $this->name_real_estate,
            // Sensitive legal documents live on the private disk: expose only temporary signed URLs.
            'image_instrument' => \App\Support\RealEstateImage::signedUrl($realEstate, 'image_instrument'),
            'copy_of_the_endowment_registration_certificate' => \App\Support\RealEstateImage::signedUrl($realEstate, 'copy_of_the_endowment_registration_certificate'),
            'copy_of_the_trusteeship_deed' => \App\Support\RealEstateImage::signedUrl($realEstate, 'copy_of_the_trusteeship_deed'),
            'is_multiple_trusteeship_deed_copy' => (bool) $this->is_multiple_trusteeship_deed_copy,
            'copy_of_guardians_power_of_attorney_for_agent' => \App\Support\RealEstateImage::signedUrl($realEstate, 'copy_of_guardians_power_of_attorney_for_agent'),
            'image_address' => \App\Support\RealEstateImage::signedUrl($realEstate, 'image_address'),
            'age_of_the_property' => $this->age_of_the_property,
            'number_of_units_per_floor' => $this->number_of_units_per_floor,
            'property_place_id' => $this->property_place_id,
            'property_city_id' => $this->property_city_id,
            'property_place_name' => optional($this->tenantEntityRegion)->name_trans
                ?? optional($this->tenantEntityRegion)->name_ar,
            'property_city_name' => optional($this->tenantEntityCity)->name_trans
                ?? optional($this->tenantEntityCity)->name_ar,
            'neighborhood' => $this->neighborhood,
            'street' => $this->street,
            'building_number' => $this->building_number,
            'postal_code' => $this->postal_code,
            'extra_figure' => $this->extra_figure,
            'address_url' => $this->address_url,
            'latitude' => $lat = $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $lng = $this->longitude !== null ? (float) $this->longitude : null,
            'lat' => $lat,
            'lng' => $lng,
            'step' => $this->step,
        ];
    }

    private function formatMysqlDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
