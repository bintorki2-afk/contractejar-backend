<?php

namespace App\Http\Requests\Api\V2\Unit;

use App\Http\Requests\Api\V2\BaseApiV2Request;
use App\Models\UnitType;
use App\Models\UnitUsage;
use App\Services\ContractUnitsService;
use App\Support\TypeFurnished;
use Illuminate\Validation\Validator;

/**
 * Create one or more property units in a single request.
 *
 * Canonical body: `{ real_estates_units_id, units: [ {...}, ... ] }`
 * A legacy flat single-unit body (unit_number, unit_type_id, …) is still accepted.
 */
class StoreUnitsRequest extends BaseApiV2Request
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (! $this->hasUnitsArray() && $this->filled('unit_number')) {
            $keys = array_merge(ContractUnitsService::unitPayloadKeys(), ['kitchen_cabinets']);
            $this->merge(['units' => [$this->only($keys)]]);
        }

        $units = $this->input('units');
        if (! is_array($units)) {
            return;
        }

        $normalizedUnits = [];
        foreach ($units as $unit) {
            if (! is_array($unit)) {
                continue;
            }
            $normalizedUnits[] = $this->normalizeUnitItem($unit);
        }

        $this->merge(['units' => $normalizedUnits]);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'real_estates_units_id' => 'required|integer|exists:real_estates,id',
            'units' => 'required|array|min:1|max:50',
            'units.*.contract_type' => 'nullable|in:housing,commercial',
            'units.*.unit_type_id' => 'required|integer|exists:unit_types,id',
            'units.*.unit_usage_id' => 'required|integer|exists:unit_usages,id',
            'units.*.unit_number' => 'required|string|max:255',
            'units.*.floor_number' => 'required|integer',
            'units.*.unit_area' => 'required|numeric',
            'units.*.tootal_rooms' => 'required|integer|min:0',
            'units.*.The_number_of_halls' => 'nullable|integer|min:0',
            'units.*.The_number_of_kitchens' => 'nullable|integer|min:0',
            'units.*.The_number_of_toilets' => 'nullable|integer|min:0',
            'units.*.The_number_of_the_toilet' => 'nullable|integer|min:0',
            'units.*.window_ac' => 'nullable|integer|min:0',
            'units.*.split_ac' => 'nullable|integer|min:0',
            'units.*.electricity_meter_number' => 'nullable|string|max:255',
            'units.*.water_meter_number' => 'nullable|string|max:255',
            'units.*.kitchen_tank' => 'nullable|boolean',
            'units.*.kitchen_cabinets' => 'nullable|boolean',
            'units.*.furnished' => 'nullable|boolean',
            'units.*.type_furnished' => TypeFurnished::rules(),
            'units.*.electricity_meter' => 'nullable|boolean',
            'units.*.water_meter' => 'nullable|boolean',
            'units.*.electricity_meter_ownership' => 'nullable|in:owner,tenant,shared',
            'units.*.water_meter_ownership' => 'nullable|in:owner,tenant,shared',
            'units.*.electricity_shared_monthly_fee' => 'nullable|numeric|min:0|max:1000000',
            'units.*.water_shared_monthly_fee' => 'nullable|numeric|min:0|max:1000000',
            'units.*.Number_parking_spaces' => 'nullable|string|max:255',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $units = $this->input('units');
            if (! is_array($units) || $units === []) {
                $validator->errors()->add('units', 'يجب إرسال وحدة واحدة على الأقل.');

                return;
            }

            $typeIds = collect($units)->pluck('unit_type_id')->filter()->unique()->all();
            $usageIds = collect($units)->pluck('unit_usage_id')->filter()->unique()->all();

            $types = $typeIds === []
                ? collect()
                : UnitType::query()->whereIn('id', $typeIds)->get()->keyBy('id');
            $usages = $usageIds === []
                ? collect()
                : UnitUsage::query()->whereIn('id', $usageIds)->get()->keyBy('id');

            foreach ($units as $index => $unit) {
                if (! is_array($unit)) {
                    continue;
                }

                $this->validateKitchenCabinets($validator, $index, $unit);
                $this->validateCatalogContractType($validator, $index, $unit, $types, $usages);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $unit
     * @param  \Illuminate\Support\Collection<int, UnitType>  $types
     * @param  \Illuminate\Support\Collection<int, UnitUsage>  $usages
     */
    private function validateCatalogContractType(
        Validator $validator,
        int $index,
        array $unit,
        $types,
        $usages
    ): void {
        $contractType = $unit['contract_type'] ?? null;
        if (! is_string($contractType) || $contractType === '') {
            return;
        }

        $typeId = $unit['unit_type_id'] ?? null;
        if ($typeId && $types->has((int) $typeId) && $types->get((int) $typeId)->contract_type !== $contractType) {
            $validator->errors()->add(
                "units.{$index}.unit_type_id",
                'نوع الوحدة لا يطابق نوع الاستخدام (سكني أو تجاري).'
            );
        }

        $usageId = $unit['unit_usage_id'] ?? null;
        if ($usageId && $usages->has((int) $usageId) && $usages->get((int) $usageId)->contract_type !== $contractType) {
            $validator->errors()->add(
                "units.{$index}.unit_usage_id",
                'استخدام الوحدة لا يطابق نوع الاستخدام (سكني أو تجاري).'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $unit
     */
    private function validateKitchenCabinets(Validator $validator, int $index, array $unit): void
    {
        $cabinets = $unit['kitchen_tank'] ?? $unit['kitchen_cabinets'] ?? false;
        if (! filter_var($cabinets, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $kitchens = (int) ($unit['The_number_of_kitchens'] ?? 0);
        if ($kitchens < 1) {
            $validator->errors()->add(
                "units.{$index}.kitchen_tank",
                'حدد عدد المطابخ أولاً.'
            );
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $base = parent::attributes();
        $nested = [];
        foreach ($base as $key => $label) {
            $nested["units.*.{$key}"] = $label;
        }

        return array_merge($base, $nested, [
            'real_estates_units_id' => 'العقار',
            'units' => 'الوحدات',
            'units.*.kitchen_cabinets' => 'خزائن المطبخ',
            'units.*.The_number_of_toilets' => 'عدد دورات المياه',
            'contract_type' => 'نوع استخدام الوحدة',
            'units.*.contract_type' => 'نوع استخدام الوحدة',
        ]);
    }

    public function messages(): array
    {
        return $this->contractV2ArabicMessages([
            'real_estates_units_id',
            'units',
            'unit_type_id',
            'unit_usage_id',
            'unit_number',
            'floor_number',
            'unit_area',
            'tootal_rooms',
            'contract_type',
            'units.*.unit_type_id',
            'units.*.unit_usage_id',
            'units.*.unit_number',
            'units.*.floor_number',
            'units.*.unit_area',
            'units.*.tootal_rooms',
            'units.*.contract_type',
        ], [
            'units.min' => 'يجب إرسال وحدة واحدة على الأقل.',
            'units.max' => 'لا يمكن إضافة أكثر من 50 وحدة في الطلب الواحد.',
            'units.*.tootal_rooms.required' => 'عدد الغرف مطلوب.',
            'units.*.unit_usage_id.required' => 'استخدام الوحدة مطلوب.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $unit
     * @return array<string, mixed>
     */
    private function normalizeUnitItem(array $unit): array
    {
        if (array_key_exists('kitchen_cabinets', $unit) && ! array_key_exists('kitchen_tank', $unit)) {
            $unit['kitchen_tank'] = $unit['kitchen_cabinets'];
        }

        if (array_key_exists('type_furnished', $unit)) {
            $unit['type_furnished'] = TypeFurnished::normalize($unit['type_furnished']);
        }

        foreach (['kitchen_tank', 'kitchen_cabinets', 'furnished', 'electricity_meter', 'water_meter'] as $key) {
            if (! array_key_exists($key, $unit)) {
                continue;
            }

            $value = $unit[$key];
            if ($value === '' || $value === null) {
                $unit[$key] = null;
                continue;
            }
            if (is_bool($value) || is_int($value)) {
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

    private function hasUnitsArray(): bool
    {
        $units = $this->input('units');

        return is_array($units) && $units !== [];
    }
}
