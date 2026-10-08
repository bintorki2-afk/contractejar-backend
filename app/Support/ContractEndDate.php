<?php

namespace App\Support;

use App\Models\Contract;
use Illuminate\Support\Carbon;

/**
 * تاريخ انتهاء العقد = تاريخ البداية + إجمالي الأشهر (من حقول العقد).
 * تاريخ البداية قد يكون هجرياً (DD-MM-YYYY) أو ميلادياً (YYYY-MM-DD) حسب `type_contract_starting_date`.
 */
final class ContractEndDate
{
    public static function totalMonths(Contract $contract): int
    {
        $months = (int) ($contract->total_months ?? 0);
        if ($months > 0) {
            return $months;
        }

        try {
            return (int) (DocFee::forContract($contract)['total_months'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return Carbon|null  منتصف ليل بتوقيت التطبيق (الرياض)
     */
    public static function for(Contract $contract): ?Carbon
    {
        $months = self::totalMonths($contract);
        $raw = trim((string) ($contract->contract_starting_date ?? ''));

        if ($months <= 0 || $raw === '') {
            return null;
        }

        $tz = config('app.timezone', 'Asia/Riyadh');
        $isHijri = ($contract->type_contract_starting_date ?? 'hijri') !== 'gregorian';

        // ميلادي صريح: YYYY-MM-DD
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m) === 1) {
            $year = (int) $m[1];
            if ($year >= 1900) {
                return Carbon::create($year, (int) $m[2], (int) $m[3], 0, 0, 0, $tz)->addMonths($months);
            }
        }

        $hijri = HijriDate::parseStored($raw);
        if ($hijri !== null && $isHijri) {
            [$y, $mo, $d] = HijriDate::addMonths($hijri[0], $hijri[1], $hijri[2], $months);

            return HijriDate::toGregorian($y, $mo, $d);
        }

        // ميلادي بصيغة DD-MM-YYYY
        $parts = preg_split('/[-\/]/', $raw);
        if (is_array($parts) && count($parts) === 3) {
            [$d, $mo, $y] = array_map('intval', $parts);
            if ($y >= 1900 && $mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31) {
                try {
                    return Carbon::create($y, $mo, $d, 0, 0, 0, $tz)->addMonths($months);
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }

        return Carbon::createFromTimestamp($ts, $tz)->startOfDay()->addMonths($months);
    }
}
