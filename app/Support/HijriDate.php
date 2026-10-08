<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * تحويل تقريبي بين التقويم الهجري والميلادي (التقويم الهجري الحسابي — دقة ± يوم تقريباً
 * مقارنة بأم القرى). يكفي لحساب مواعيد التذكير (قرب انتهاء العقد) وليس للمستندات الرسمية.
 */
final class HijriDate
{
    /** يوم جوليان لتاريخ هجري (حسابي). */
    public static function toJulianDay(int $year, int $month, int $day): int
    {
        return intdiv(11 * $year + 3, 30)
            + 354 * $year
            + 30 * $month
            - intdiv($month - 1, 2)
            + $day
            + 1948440
            - 385;
    }

    /**
     * @return array{0: int, 1: int, 2: int}  [year, month, day] ميلادي
     */
    public static function julianDayToGregorian(int $jd): array
    {
        $l = $jd + 68569;
        $n = intdiv(4 * $l, 146097);
        $l = $l - intdiv(146097 * $n + 3, 4);
        $i = intdiv(4000 * ($l + 1), 1461001);
        $l = $l - intdiv(1461 * $i, 4) + 31;
        $j = intdiv(80 * $l, 2447);
        $d = $l - intdiv(2447 * $j, 80);
        $l = intdiv($j, 11);
        $m = $j + 2 - 12 * $l;
        $y = 100 * ($n - 49) + $i + $l;

        return [$y, $m, $d];
    }

    public static function toGregorian(int $year, int $month, int $day): Carbon
    {
        [$y, $m, $d] = self::julianDayToGregorian(self::toJulianDay($year, $month, $day));

        return Carbon::create($y, $m, $d, 0, 0, 0, config('app.timezone', 'Asia/Riyadh'));
    }

    /**
     * إضافة أشهر هجرية إلى تاريخ هجري.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function addMonths(int $year, int $month, int $day, int $months): array
    {
        $index = $year * 12 + ($month - 1) + $months;
        $y = intdiv($index, 12);
        $m = $index - $y * 12 + 1;

        return [$y, $m, min($day, 30)];
    }

    /**
     * هل القيمة تاريخ هجري بصيغة DD-MM-YYYY (أو DD/MM/YYYY) بسنة هجرية معقولة؟
     *
     * @return array{0: int, 1: int, 2: int}|null  [year, month, day]
     */
    public static function parseStored(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $parts = preg_split('/[-\/]/', trim($value));
        if (! is_array($parts) || count($parts) !== 3) {
            return null;
        }

        [$a, $b, $c] = array_map(static fn ($p) => (int) preg_replace('/\D/', '', (string) $p), $parts);

        // DD-MM-YYYY (المخزَّن) أو YYYY-MM-DD
        if ($a > 1000) {
            [$year, $month, $day] = [$a, $b, $c];
        } else {
            [$day, $month, $year] = [$a, $b, $c];
        }

        if ($year < 1300 || $year > 1600 || $month < 1 || $month > 12 || $day < 1 || $day > 30) {
            return null;
        }

        return [$year, $month, $day];
    }
}
