<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractUnit;
use App\Models\UnitsReal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ContractUnitsService
{
    /**
     * Unit attribute keys accepted from API payloads.
     *
     * @return list<string>
     */
    public static function unitPayloadKeys(): array
    {
        return [
            'unit_type_id',
            'unit_usage_id',
            'unit_number',
            'floor_number',
            'unit_area',
            'tootal_rooms',
            'The_number_of_halls',
            'The_number_of_kitchens',
            'The_number_of_toilets',
            'The_number_of_the_toilet',
            'window_ac',
            'split_ac',
            'electricity_meter_number',
            'water_meter_number',
            'kitchen_tank',
            'furnished',
            'type_furnished',
            'electricity_meter',
            'water_meter',
            'electricity_meter_ownership',
            'water_meter_ownership',
            'electricity_shared_monthly_fee',
            'water_shared_monthly_fee',
            'Number_parking_spaces',
            'contract_type',
        ];
    }

    /**
     * Replace contract units: create new real_units and/or attach existing ones.
     *
     * @param  list<array<string, mixed>>  $unitPayloads
     * @return list<UnitsReal>
     */
    public function syncForContract(
        Contract $contract,
        array $unitPayloads,
        int $userId,
        bool $allowAnyExistingUnit = false
    ): array {
        if ($unitPayloads === []) {
            throw new InvalidArgumentException('At least one unit is required.');
        }

        $realEstateId = $contract->real_id ? (int) $contract->real_id : null;

        return DB::transaction(function () use ($contract, $unitPayloads, $userId, $realEstateId, $allowAnyExistingUnit) {
            ContractUnit::query()->where('contract_id', $contract->id)->delete();

            $units = [];
            $firstUnitId = null;

            foreach ($unitPayloads as $payload) {
                $unit = $this->resolveOrCreateUnit(
                    $payload,
                    $userId,
                    $realEstateId,
                    $contract,
                    $allowAnyExistingUnit
                );

                ContractUnit::query()->create([
                    'contract_id' => $contract->id,
                    'real_unit_id' => $unit->id,
                    'real_estate_id' => $realEstateId ?? $unit->real_estates_units_id,
                ]);

                $units[] = $unit->load(['unitType', 'unitUsage', 'realEstate']);
                $firstUnitId ??= $unit->id;
            }

            // Keep legacy single-unit pointer for old screens (not used as source of truth).
            if ($firstUnitId) {
                $contract->forceFill(['real_units_id' => $firstUnitId])->saveQuietly();
            }

            return $units;
        });
    }

    /**
     * Attach one unit to a contract without removing existing links.
     *
     * @param  array<string, mixed>  $payload
     */
    public function attachToContract(
        Contract $contract,
        array $payload,
        int $userId,
        bool $allowAnyExistingUnit = false
    ): UnitsReal {
        $realEstateId = $contract->real_id ? (int) $contract->real_id : null;

        return DB::transaction(function () use ($contract, $payload, $userId, $realEstateId, $allowAnyExistingUnit) {
            $unit = $this->resolveOrCreateUnit(
                $payload,
                $userId,
                $realEstateId,
                $contract,
                $allowAnyExistingUnit
            );

            $alreadyLinked = ContractUnit::query()
                ->where('contract_id', $contract->id)
                ->where('real_unit_id', $unit->id)
                ->exists();

            if (! $alreadyLinked) {
                ContractUnit::query()->create([
                    'contract_id' => $contract->id,
                    'real_unit_id' => $unit->id,
                    'real_estate_id' => $realEstateId ?? $unit->real_estates_units_id,
                ]);
            }

            if (! $contract->real_units_id) {
                $contract->forceFill(['real_units_id' => $unit->id])->saveQuietly();
            }

            return $unit->load(['unitType', 'unitUsage', 'realEstate']);
        });
    }

    /**
     * Update a unit that is linked to the contract.
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateLinkedUnit(Contract $contract, int $unitId, array $payload): UnitsReal
    {
        $linked = ContractUnit::query()
            ->where('contract_id', $contract->id)
            ->where('real_unit_id', $unitId)
            ->first();

        if (! $linked) {
            throw new InvalidArgumentException(trans('api.contract_unit_not_found') ?: 'Unit not linked to this contract.');
        }

        $unit = UnitsReal::query()->findOrFail($unitId);
        $userId = (int) ($unit->user_id ?: $contract->user_id);
        $realEstateId = $contract->real_id ? (int) $contract->real_id : ($unit->real_estates_units_id ? (int) $unit->real_estates_units_id : null);

        $data = $this->normalizeUnitPayload($payload, $userId, $realEstateId, $contract);
        unset($data['user_id'], $data['real_estates_units_id']);

        $unit->fill(UnitsReal::attributesForApi($data))->save();

        return $unit->fresh(['unitType', 'unitUsage', 'realEstate']);
    }

    /**
     * Detach a unit from the contract (does not delete the real_units row).
     */
    public function detachFromContract(Contract $contract, int $unitId): void
    {
        $deleted = ContractUnit::query()
            ->where('contract_id', $contract->id)
            ->where('real_unit_id', $unitId)
            ->delete();

        if (! $deleted) {
            throw new InvalidArgumentException(trans('api.contract_unit_not_found') ?: 'Unit not linked to this contract.');
        }

        if ((int) $contract->real_units_id === $unitId) {
            $nextId = ContractUnit::query()
                ->where('contract_id', $contract->id)
                ->value('real_unit_id');

            $contract->forceFill(['real_units_id' => $nextId])->saveQuietly();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveOrCreateUnit(
        array $payload,
        int $userId,
        ?int $realEstateId,
        Contract $contract,
        bool $allowAnyExistingUnit = false
    ): UnitsReal {
        $existingId = $payload['unit_id'] ?? $payload['id'] ?? $payload['real_unit_id'] ?? null;

        if ($existingId) {
            $query = UnitsReal::query()->whereKey((int) $existingId);

            if (! $allowAnyExistingUnit) {
                $query->where('user_id', $userId);
            }

            $unit = $query->first();

            if (! $unit) {
                throw new InvalidArgumentException(trans('api.not_have_unit') ?: 'Unit not found.');
            }

            return $unit;
        }

        // real_estate_id may be null until property is saved; units still belong to the user/contract.
        $data = $this->normalizeUnitPayload($payload, $userId, $realEstateId, $contract);

        return UnitsReal::query()->create(UnitsReal::attributesForApi($data));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeUnitPayload(array $payload, int $userId, ?int $realEstateId, Contract $contract): array
    {
        $toilets = $payload['The_number_of_toilets']
            ?? $payload['The_number_of_the_toilet']
            ?? null;

        $data = [
            'real_estates_units_id' => $realEstateId,
            'user_id' => $userId,
            'contract_type' => $payload['contract_type'] ?? $contract->contract_type,
            'unit_type_id' => $payload['unit_type_id'] ?? null,
            'unit_usage_id' => $payload['unit_usage_id'] ?? null,
            'unit_number' => $payload['unit_number'] ?? null,
            'floor_number' => $payload['floor_number'] ?? null,
            'unit_area' => $payload['unit_area'] ?? null,
            'tootal_rooms' => $payload['tootal_rooms'] ?? null,
            'The_number_of_halls' => $payload['The_number_of_halls'] ?? null,
            'The_number_of_kitchens' => $payload['The_number_of_kitchens'] ?? null,
            'The_number_of_toilets' => $toilets,
            'window_ac' => $payload['window_ac'] ?? null,
            'split_ac' => $payload['split_ac'] ?? null,
            'electricity_meter_number' => $payload['electricity_meter_number'] ?? null,
            'water_meter_number' => $payload['water_meter_number'] ?? null,
            'kitchen_tank' => (int) filter_var($payload['kitchen_tank'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'furnished' => (int) filter_var($payload['furnished'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'type_furnished' => \App\Support\TypeFurnished::normalize($payload['type_furnished'] ?? null),
            'electricity_meter' => (int) filter_var($payload['electricity_meter'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'water_meter' => (int) filter_var($payload['water_meter'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'electricity_meter_ownership' => $this->nullableOwnership($payload['electricity_meter_ownership'] ?? null),
            'water_meter_ownership' => $this->nullableOwnership($payload['water_meter_ownership'] ?? null),
            'electricity_shared_monthly_fee' => ($payload['electricity_meter_ownership'] ?? null) === 'shared' && isset($payload['electricity_shared_monthly_fee'])
                ? (float) $payload['electricity_shared_monthly_fee'] : null,
            'water_shared_monthly_fee' => ($payload['water_meter_ownership'] ?? null) === 'shared' && isset($payload['water_shared_monthly_fee'])
                ? (float) $payload['water_shared_monthly_fee'] : null,
        ];

        if (array_key_exists('Number_parking_spaces', $payload)) {
            $data['Number_parking_spaces'] = $payload['Number_parking_spaces'];
        }

        return $data;
    }

    private function nullableOwnership(mixed $value): ?string
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return (string) $value;
    }
}
