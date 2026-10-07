<?php

namespace App\Http\Requests\Api\V2\Unit;

use App\Support\TypeFurnished;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $keys = ['kitchen_tank', 'furnished', 'electricity_meter', 'water_meter'];
        $normalized = [];

        foreach ($keys as $key) {
            if (! $this->exists($key)) {
                continue;
            }

            $value = $this->input($key);
            if ($value === null || $value === '') {
                $normalized[$key] = null;
                continue;
            }

            if (is_bool($value) || is_int($value)) {
                $normalized[$key] = $value;
                continue;
            }

            if (is_string($value)) {
                $trimmed = strtolower(trim($value));
                if (in_array($trimmed, ['0', '1'], true)) {
                    $normalized[$key] = (int) $trimmed;
                    continue;
                }
                if (in_array($trimmed, ['true', 'false'], true)) {
                    $normalized[$key] = $trimmed === 'true' ? 1 : 0;
                }
            }
        }

        if ($this->exists('type_furnished')) {
            $normalized['type_furnished'] = TypeFurnished::normalize($this->input('type_furnished'));
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            'real_estates_units_id' => 'sometimes|exists:real_estates,id',
            'unit_type_id' => 'sometimes|exists:unit_types,id',
            'unit_usage_id' => 'nullable|sometimes|exists:unit_usages,id',
            'contract_type' => 'nullable|sometimes|in:housing,commercial',
            'unit_number' => 'sometimes|string|max:255',
            'floor_number' => 'sometimes|integer|max:15',
            'unit_area' => 'sometimes|numeric',
            'tootal_rooms' => 'sometimes|integer|max:10',
            'The_number_of_halls' => 'sometimes|integer|max:10',
            'The_number_of_kitchens' => 'sometimes|integer|max:10',
            'The_number_of_toilets' => 'sometimes|integer|max:10',
            'window_ac' => 'sometimes|max:10',
            'split_ac' => 'sometimes|max:10',
            'electricity_meter_number' => 'nullable|string|max:255',
            'water_meter_number' => 'nullable|string|max:255',
            'kitchen_tank' => 'sometimes|boolean',
            'furnished' => 'sometimes|boolean',
            'type_furnished' => TypeFurnished::rules(true),
            'electricity_meter' => 'sometimes|boolean',
            'water_meter' => 'sometimes|boolean',
            'electricity_meter_ownership' => 'nullable|in:owner,tenant,shared',
            'water_meter_ownership' => 'nullable|in:owner,tenant,shared',
            'electricity_shared_monthly_fee' => 'nullable|numeric|min:0|max:1000000',
            'water_shared_monthly_fee' => 'nullable|numeric|min:0|max:1000000',
        ];
    }
}
