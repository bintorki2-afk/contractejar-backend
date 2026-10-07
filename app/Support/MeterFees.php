<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\Setting;

/**
 * Resolve electricity/water meter fees for a contract when ownership is tenant.
 */
final class MeterFees
{
    /**
     * @return array{
     *     electricity_meter_fee: float,
     *     water_meter_fee: float,
     *     meter_fees_total: float
     * }
     */
    public static function forContract(Contract $contract, ?Setting $setting = null): array
    {
        $setting ??= Setting::query()->first();

        $electricity = 0.0;
        $water = 0.0;

        if ($setting) {
            $isHousing = $contract->contract_type === 'housing';

            // Contracts created before Step 5 rolled unit ownership up to the contract only carry
            // the ownership on their units: derive it from them when the contract column is empty.
            $electricityOwnership = $contract->electricity_meter_ownership ?? self::ownershipFromUnits($contract, 'electricity_meter_ownership');
            $waterOwnership = $contract->water_meter_ownership ?? self::ownershipFromUnits($contract, 'water_meter_ownership');

            if ($electricityOwnership === 'tenant') {
                $electricity = (float) ($isHousing
                    ? $setting->electricity_meter_fee_housing_tenant
                    : $setting->electricity_meter_fee_commercial_tenant);
            }

            if ($waterOwnership === 'tenant') {
                $water = (float) ($isHousing
                    ? $setting->water_meter_fee_housing_tenant
                    : $setting->water_meter_fee_commercial_tenant);
            }
        }

        $electricity = max(0, $electricity);
        $water = max(0, $water);

        return [
            'electricity_meter_fee' => $electricity,
            'water_meter_fee' => $water,
            'meter_fees_total' => $electricity + $water,
            'shared_meters' => self::sharedMetersForContract($contract),
        ];
    }

    /**
     * العداد المشترك: مبلغ شهري يدفعه المستأجر × مدة العقد — بند من بنود العقد بين الطرفين
     * (يُعرض فقط، ولا يدخل في إجمالي رسومنا).
     *
     * @return array{
     *     electricity: array{monthly: float, months: int, total: float}|null,
     *     water: array{monthly: float, months: int, total: float}|null,
     *     total: float
     * }
     */
    public static function sharedMetersForContract(Contract $contract): array
    {
        $months = 0;
        try {
            $months = (int) ($contract->total_months ?: (DocFee::forContract($contract)['total_months'] ?? 0));
        } catch (\Throwable) {
            $months = 0;
        }

        $result = ['electricity' => null, 'water' => null, 'total' => 0.0];

        if (! $contract->exists) {
            return $result;
        }

        try {
            $units = $contract->relationLoaded('units') ? $contract->units : $contract->units()->get();
        } catch (\Throwable) {
            return $result;
        }

        foreach (['electricity', 'water'] as $kind) {
            $monthly = 0.0;
            foreach ($units as $unit) {
                if (($unit->{$kind.'_meter_ownership'} ?? null) === 'shared') {
                    $monthly += (float) ($unit->{$kind.'_shared_monthly_fee'} ?? 0);
                }
            }
            if ($monthly > 0) {
                $result[$kind] = [
                    'monthly' => round($monthly, 2),
                    'months' => $months,
                    'total' => round($monthly * $months, 2),
                ];
                $result['total'] += $result[$kind]['total'];
            }
        }

        $result['total'] = round($result['total'], 2);

        return $result;
    }

    private static function ownershipFromUnits(Contract $contract, string $column): ?string
    {
        if (! $contract->exists) {
            return null;
        }

        try {
            $values = $contract->relationLoaded('units')
                ? $contract->units->pluck($column)->filter()->values()
                : $contract->units()->pluck($column)->filter()->values();
        } catch (\Throwable) {
            return null;
        }

        if ($values->isEmpty()) {
            return null;
        }

        return $values->contains('tenant') ? 'tenant' : 'owner';
    }

    public static function totalForContract(Contract $contract, ?Setting $setting = null): float
    {
        return self::forContract($contract, $setting)['meter_fees_total'];
    }
}
