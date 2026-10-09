<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * تحويل بين التقويم الهجري والميلادي.
 * متابعة دفعة (د): يُستخدم تقويم **أم القرى** (ext-intl: `@calendar=islamic-umalqura` — نفس ما تعتمده منصة إيجار
 * والموقع عبر Intl) متى توفّر، وإلا الحساب التقريبي (± يوم) احتياطاً.
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

    public static function umAlQuraAvailable(): bool
    {
        return class_exists(\IntlCalendar::class) && class_exists(\IntlDateFormatter::class);
    }

    public static function toGregorian(int $year, int $month, int $day): Carbon
    {
        if (self::umAlQuraAvailable()) {
            try {
                $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'en_US@calendar=islamic-umalqura');
                if ($cal !== null) {
                    $cal->clear();
                    $cal->set($year, $month - 1, $day, 12, 0, 0);
                    $ts = (int) floor($cal->getTime() / 1000);

                    return Carbon::createFromTimestamp($ts, 'Asia/Riyadh')->startOfDay()->setTimezone(config('app.timezone', 'Asia/Riyadh'));
                }
            } catch (\Throwable) {
                // احتياط حسابي
            }
        }

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

    /**
     * ميلادي → هجري (حسابي، ± يوم). دفعة (د) — ب15.
     *
     * @return array{0: int, 1: int, 2: int}  [year, month, day] هجري
     */
    public static function fromGregorian(Carbon $date): array
    {
        if (self::umAlQuraAvailable()) {
            try {
                $fmt = new \IntlDateFormatter('en_US@calendar=islamic-umalqura', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE,
                    'Asia/Riyadh', \IntlDateFormatter::TRADITIONAL, 'y-M-d');
                $noon = new \DateTime($date->format('Y-m-d').' 12:00:00', new \DateTimeZone('Asia/Riyadh'));
                $out = $fmt->format($noon);
                if (is_string($out) && preg_match('/^(\d+)-(\d+)-(\d+)$/', $out, $m)) {
                    return [(int) $m[1], (int) $m[2], (int) $m[3]];
                }
            } catch (\Throwable) {
                // احتياط حسابي
            }
        }

        $y = (int) $date->year;
        $m = (int) $date->month;
        $d = (int) $date->day;
        $a = intdiv(14 - $m, 12);
        $y2 = $y + 4800 - $a;
        $m2 = $m + 12 * $a - 3;
        $jd = $d + intdiv(153 * $m2 + 2, 5) + 365 * $y2 + intdiv($y2, 4) - intdiv($y2, 100) + intdiv($y2, 400) - 32045;

        $l = $jd - 1948440 + 10632;
        $n = intdiv($l - 1, 10631);
        $l = $l - 10631 * $n + 354;
        $j = intdiv(10985 - $l, 5316) * intdiv(50 * $l, 17719) + intdiv($l, 5670) * intdiv(43 * $l, 15238);
        $l = $l - intdiv(30 - $j, 15) * intdiv(17719 * $j, 50) - intdiv($j, 16) * intdiv(15238 * $j, 43) + 29;
        $hm = intdiv(24 * $l, 709);
        $hd = $l - intdiv(709 * $hm, 24);
        $hy = 30 * $n + $j - 30;

        return [$hy, $hm, $hd];
    }
}
