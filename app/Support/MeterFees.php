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
     * دفعة (د) — ب1: رسوم نقل العداد **لكل عداد**: لكل وحدة عدادها باسم المستأجر يُحسب رسم مستقل
     * (وحدتان × عداد كهرباء باسم المستأجر = 2 × 15). إذا لم تُسجَّل وحدات، تُستخدم ملكية العقد (عداد واحد).
     *
     * @return array{
     *     electricity_meter_fee: float,
     *     water_meter_fee: float,
     *     meter_fees_total: float,
     *     electricity_meter_count: int,
     *     water_meter_count: int,
     *     electricity_meter_unit_fee: float,
     *     water_meter_unit_fee: float,
     *     shared_meters: array<string, mixed>
     * }
     */
    public static function forContract(Contract $contract, ?Setting $setting = null): array
    {
        $setting ??= Setting::query()->first();

        $electricityUnit = 0.0;
        $waterUnit = 0.0;
        $electricityCount = 0;
        $waterCount = 0;

        if ($setting) {
            $isHousing = $contract->contract_type === 'housing';

            $electricityUnit = max(0, (float) ($isHousing
                ? $setting->electricity_meter_fee_housing_tenant
                : $setting->electricity_meter_fee_commercial_tenant));
            $waterUnit = max(0, (float) ($isHousing
                ? $setting->water_meter_fee_housing_tenant
                : $setting->water_meter_fee_commercial_tenant));

            $electricityCount = self::tenantMeterCount($contract, 'electricity');
            $waterCount = self::tenantMeterCount($contract, 'water');
        }

        $electricity = round($electricityUnit * $electricityCount, 2);
        $water = round($waterUnit * $waterCount, 2);

        return [
            'electricity_meter_fee' => $electricity,
            'water_meter_fee' => $water,
            'meter_fees_total' => round($electricity + $water, 2),
            'electricity_meter_count' => $electricityCount,
            'water_meter_count' => $waterCount,
            'electricity_meter_unit_fee' => $electricityUnit,
            'water_meter_unit_fee' => $waterUnit,
            'shared_meters' => self::sharedMetersForContract($contract),
        ];
    }

    /**
     * عدد العدادات باسم المستأجر لنوع (electricity|water): وحدة لكل عداد.
     */
    public static function tenantMeterCount(Contract $contract, string $kind): int
    {
        $column = $kind.'_meter_ownership';

        if ($contract->exists) {
            try {
                $units = $contract->relationLoaded('units') ? $contract->units : $contract->units()->get();
            } catch (\Throwable) {
                $units = collect();
            }

            $withOwnership = $units->filter(static fn ($u) => filled($u->{$column} ?? null));
            if ($withOwnership->isNotEmpty()) {
                return $withOwnership->filter(static fn ($u) => ($u->{$column} ?? null) === 'tenant')->count();
            }
        }

        // لا وحدات بملكية مسجّلة ⇒ ملكية العقد نفسه (عداد واحد).
        return ($contract->{$column} ?? null) === 'tenant' ? 1 : 0;
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
        // دفعة (د) — ب7: الأشهر = مدة العقد كاملة (سنوي = 12) — لا ×0 عند yearly أو total_months الفارغ.
        try {
            $months = DocFee::contractMonths($contract);
        } catch (\Throwable) {
            $months = 12;
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

    /**
     * حقول عدد العدادات وسعر العداد (ب1) لإضافتها لأي حمولة تحمل رسوم العدادات.
     *
     * @param  array<string, mixed>  $meterFees
     * @return array{electricity_meter_count: int, water_meter_count: int, electricity_meter_unit_fee: float, water_meter_unit_fee: float}
     */
    public static function countFields(array $meterFees): array
    {
        return [
            'electricity_meter_count' => (int) ($meterFees['electricity_meter_count'] ?? 0),
            'water_meter_count' => (int) ($meterFees['water_meter_count'] ?? 0),
            'electricity_meter_unit_fee' => (float) ($meterFees['electricity_meter_unit_fee'] ?? 0),
            'water_meter_unit_fee' => (float) ($meterFees['water_meter_unit_fee'] ?? 0),
        ];
    }

    public static function totalForContract(Contract $contract, ?Setting $setting = null): float
    {
        return self::forContract($contract, $setting)['meter_fees_total'];
    }
}
