<?php

namespace App\Http\Requests\Api\V2\RealEstate;

use App\Http\Requests\Api\V2\BaseApiV2Request;
use App\Services\ContractUnitsService;
use App\Support\TypeFurnished;

/**
 * V2 real-estate step 3: optional units (same shape as contract step5).
 * Units may be omitted so the client can finish the property without attaching units yet.
 */
class Step3RealEstateRequest extends BaseApiV2Request
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('type_furnished')) {
            $this->merge([
                'type_furnished' => TypeFurnished::normalize($this->input('type_furnished')),
            ]);
        }

        if (! $this->filled('units') && $this->filled('unit_number')) {
            $flat = $this->only(ContractUnitsService::unitPayloadKeys());
            $this->merge(['units' => [$flat]]);
        }

        if ($this->filled('unit_ids') && is_array($this->input('unit_ids'))) {
            $fromIds = collect($this->input('unit_ids'))
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->map(fn ($id) => ['unit_id' => (int) $id])
                ->values()
                ->all();

            $existingUnits = is_array($this->input('units')) ? $this->input('units') : [];
            $this->merge(['units' => array_merge($existingUnits, $fromIds)]);
        }

        $units = $this->input('units');
        if (is_array($units)) {
            $normalizedUnits = [];
            foreach ($units as $unit) {
                if (! is_array($unit)) {
                    continue;
                }
                $normalizedUnits[] = $this->normalizeUnitItem($unit);
            }
            $this->merge(['units' => $normalizedUnits]);
        }
    }

    /**
     * @param  array<string, mixed>  $unit
     * @return array<string, mixed>
     */
    private function normalizeUnitItem(array $unit): array
    {
        if (array_key_exists('type_furnished', $unit)) {
            $unit['type_furnished'] = TypeFurnished::normalize($unit['type_furnished']);
        }

        foreach (['kitchen_tank', 'furnished', 'electricity_meter', 'water_meter'] as $key) {
            if (! array_key_exists($key, $unit)) {
                $unit[$key] = 0;
                continue;
            }

            $value = $unit[$key];
            if ($value === '' || $value === null) {
                $unit[$key] = null;
                continue;
            }
            if (is_bool($value) || is_int($value)) {
                $unit[$key] = $value;
                continue;
            }
            if (is_string($value)) {
                $trimmed = strtolower(trim($value));
                if (in_array($trimmed, ['0', '1'], true)) {
                    $unit[$key] = (int) $trimmed;
                } elseif (in_array($trimmed, ['true', 'false'], true)) {
                    $unit[$key] = $trimmed === 'true' ? 1 : 0;
                }
            }
        }

        foreach (['electricity_meter_ownership', 'water_meter_ownership'] as $key) {
            if (! array_key_exists($key, $unit)) {
                continue;
            }
            $value = $unit[$key];
            $unit[$key] = ($value === '' || $value === null) ? null : $value;
        }

        return $unit;
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|exists:real_estates,id',
            'units' => 'nullable|array|max:50',
            'units.*.unit_id' => 'nullable|integer|exists:real_units,id',
            'units.*.id' => 'nullable|integer|exists:real_units,id',
            'units.*.real_unit_id' => 'nullable|integer|exists:real_units,id',
            'units.*.unit_type_id' => 'required_without_all:units.*.unit_id,units.*.id,units.*.real_unit_id|nullable|integer|exists:unit_types,id',
            'units.*.unit_usage_id' => 'nullable|integer|exists:unit_usages,id',
            'units.*.unit_number' => 'required_without_all:units.*.unit_id,units.*.id,units.*.real_unit_id|nullable|string|max:255',
            'units.*.floor_number' => 'required_without_all:units.*.unit_id,units.*.id,units.*.real_unit_id|nullable|integer',
            'units.*.unit_area' => 'required_without_all:units.*.unit_id,units.*.id,units.*.real_unit_id|nullable|numeric',
            'units.*.tootal_rooms' => 'nullable|integer',
            'units.*.The_number_of_halls' => 'nullable|integer',
            'units.*.The_number_of_kitchens' => 'nullable|integer',
            'units.*.The_number_of_toilets' => 'nullable|integer',
            'units.*.The_number_of_the_toilet' => 'nullable|integer',
            'units.*.window_ac' => 'nullable|integer',
            'units.*.split_ac' => 'nullable|integer',
            'units.*.electricity_meter_number' => 'nullable|string|max:255',
            'units.*.water_meter_number' => 'nullable|string|max:255',
            'units.*.kitchen_tank' => 'nullable|boolean',
            'units.*.furnished' => 'nullable|boolean',
            'units.*.type_furnished' => TypeFurnished::rules(),
            'units.*.electricity_meter' => 'nullable|boolean',
            'units.*.water_meter' => 'nullable|boolean',
            'units.*.electricity_meter_ownership' => 'nullable|in:owner,tenant,shared',
            'units.*.water_meter_ownership' => 'nullable|in:owner,tenant,shared',
            'units.*.Number_parking_spaces' => 'nullable|string|max:255',
            'units.*.contract_type' => 'nullable|in:housing,commercial',
            'unit_ids' => 'nullable|array',
            'unit_ids.*' => 'integer|exists:real_units,id',
        ];
    }

    public function messages(): array
    {
        return $this->contractV2ArabicMessages([
            'id',
            'units',
            'unit_type_id',
            'unit_usage_id',
            'unit_number',
            'floor_number',
            'unit_area',
        ]);
    }
}
