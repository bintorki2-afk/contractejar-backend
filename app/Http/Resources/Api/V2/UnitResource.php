<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();

        $data = [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'real_estates_units_id' => $this->real_estates_units_id,
            'unit_number' => $this->unit_number,
            'unit_type_id' => $this->unit_type_id,
            'unit_type_name' => optional($this->unitType)->name_trans
                ?? optional($this->unitType)->name_ar,
            'contract_type' => $this->contract_type
                ?? optional($this->realEstate)->contract_type
                ?? optional($this->unitType)->contract_type,
            'unit_usage_id' => $this->unit_usage_id,
            'unit_usage_name' => optional($this->unitUsage)->name_trans
                ?? optional($this->unitUsage)->name_ar,
            'floor_number' => $this->floor_number,
            'unit_area' => $this->unit_area,
            'tootal_rooms' => $this->tootal_rooms,
            'The_number_of_halls' => $this->The_number_of_halls,
            'The_number_of_kitchens' => $this->The_number_of_kitchens,
            'The_number_of_toilets' => $this->The_number_of_toilets,
            'window_ac' => $this->window_ac,
            'split_ac' => $this->split_ac,
            'electricity_meter_number' => $this->electricity_meter_number,
            'water_meter_number' => $this->water_meter_number,
            'kitchen_tank' => (bool) $this->kitchen_tank,
            'kitchen_cabinets' => (bool) $this->kitchen_tank,
            'furnished' => (bool) $this->furnished,
            'type_furnished' => $this->type_furnished,
            'electricity_meter' => (bool) $this->electricity_meter,
            'water_meter' => (bool) $this->water_meter,
            'electricity_meter_ownership' => $this->electricity_meter_ownership,
            'water_meter_ownership' => $this->water_meter_ownership,
            'electricity_shared_monthly_fee' => $this->electricity_shared_monthly_fee !== null ? (float) $this->electricity_shared_monthly_fee : null,
            'water_shared_monthly_fee' => $this->water_shared_monthly_fee !== null ? (float) $this->water_shared_monthly_fee : null,
            'sub_delay' => $this->sub_delay,
            'property_city_id' => $this->property_city_id,
            'Number_parking_spaces' => $this->Number_parking_spaces,
            'Gasmeter' => $this->Gasmeter,
            'number_of_unit_air_conditioners' => $this->number_of_unit_air_conditioners,
            'Services' => $this->Services,
            'is_deleted' => $this->is_deleted ?? 0,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        foreach (['real_estates_units', 'unit_usage', 'number_of_rooms', 'The_number_of_the_toilet'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $data[$column] = $this->{$column};
            }
        }

        return $data;
    }
}
