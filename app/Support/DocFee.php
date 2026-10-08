<?php

namespace App\Support;

/**
 * رسوم التوثيق حسب إجمالي الأشهر ونوع العقد.
 * القاعدة: أي جزء من سنة = سنة كاملة.
 */
final class DocFee
{
    public const HOUSING_FIRST_YEAR = 249.0;

    public const HOUSING_EXTRA_YEAR = 150.0;

    public const COMMERCIAL_FIRST_YEAR = 349.0;

    public const COMMERCIAL_EXTRA_YEAR = 250.0;

    /** القيم الفعلية تُقرأ من الإعدادات (قابلة للتعديل من لوحة التحكم)؛ الثوابت أعلاه احتياط فقط. */
    private static ?array $settingsCache = null;

    public static function flushSettingsCache(): void
    {
        self::$settingsCache = null;
        // كاش النقاط العامة (الأسعار/الإعدادات/...) يتبع نفس دورة الحياة.
        PublicCache::flush();
    }

    /** إعادة قراءة الأسعار من الإعدادات في هذه العملية فقط (بدون تفريغ كاش النقاط العامة). */
    public static function resetRatesCache(): void
    {
        self::$settingsCache = null;
    }

    /** @return array{housing_first: float, housing_extra: float, commercial_first: float, commercial_extra: float} */
    public static function rates(): array
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }

        $defaults = [
            'housing_first' => self::HOUSING_FIRST_YEAR,
            'housing_extra' => self::HOUSING_EXTRA_YEAR,
            'commercial_first' => self::COMMERCIAL_FIRST_YEAR,
            'commercial_extra' => self::COMMERCIAL_EXTRA_YEAR,
        ];

        try {
            $setting = \App\Models\Setting::query()->first();
        } catch (\Throwable) {
            $setting = null;
        }

        if ($setting) {
            $map = [
                'housing_first' => 'doc_fee_housing_first_year',
                'housing_extra' => 'doc_fee_housing_extra_year',
                'commercial_first' => 'doc_fee_commercial_first_year',
                'commercial_extra' => 'doc_fee_commercial_extra_year',
            ];
            foreach ($map as $key => $column) {
                $value = $setting->getAttribute($column);
                if ($value !== null && is_numeric($value) && (float) $value > 0) {
                    $defaults[$key] = (float) $value;
                }
            }
        }

        return self::$settingsCache = $defaults;
    }

    public const PRESETS = [
        '3_months' => 3,
        '6_months' => 6,
        '1_year' => 12,
        '2_years' => 24,
    ];

    public static function presetKeys(): array
    {
        return array_merge(array_keys(self::PRESETS), ['other']);
    }

    /** إجمالي الأشهر من سنة + شهر */
    public static function totalMonths(int $years = 0, int $months = 0): int
    {
        return max(0, $years) * 12 + max(0, $months);
    }

    /** أشهر الزر الجاهز */
    public static function monthsFromPreset(string $preset): int
    {
        return self::PRESETS[$preset] ?? 0;
    }

    /** حساب الأشهر من الطلب */
    public static function resolveTotalMonths(string $preset, int $years = 0, int $extraMonths = 0): int
    {
        if ($preset === 'other') {
            return self::totalMonths($years, $extraMonths);
        }

        return self::monthsFromPreset($preset);
    }

    /** عدد سنوات الرسوم (أي جزء يحسب سنة) */
    public static function billableYears(int $totalMonths): int
    {
        if ($totalMonths <= 0) {
            return 0;
        }

        return (int) ceil($totalMonths / 12);
    }

    /** هل فيه أشهر إضافية ضمن «مدة أخرى» */
    public static function hasExtraMonths(string $preset, int $extraMonths): bool
    {
        return $preset === 'other' && $extraMonths > 0;
    }

    /** السنة الأولى */
    public static function firstYearFee(string $contractType): float
    {
        $rates = self::rates();

        return $contractType === 'commercial' ? $rates['commercial_first'] : $rates['housing_first'];
    }

    /** كل سنة إضافية */
    public static function extraYearFee(string $contractType): float
    {
        $rates = self::rates();

        return $contractType === 'commercial' ? $rates['commercial_extra'] : $rates['housing_extra'];
    }

    /** الرقم فقط */
    public static function amount(int $totalMonths, string $contractType): float
    {
        $years = self::billableYears($totalMonths);

        if ($years <= 0) {
            return 0.0;
        }

        return self::firstYearFee($contractType)
            + ($years - 1) * self::extraYearFee($contractType);
    }

    /**
     * أسطر العرض الحرفية
     *
     * @return list<string>
     */
    public static function lines(int $totalMonths, string $contractType, bool $hasExtraMonths = false): array
    {
        $years = self::billableYears($totalMonths);

        if ($years <= 0) {
            return [];
        }

        $first = (int) self::firstYearFee($contractType);
        $extra = (int) self::extraYearFee($contractType);
        $fee = (int) self::amount($totalMonths, $contractType);

        $line1 = "( عدد سنوات العقد {$years} سنة — السنة الأولى {$first} )";

        if ($hasExtraMonths) {
            $line1 .= ' — الأشهر الإضافية تُحسب سنة رسومها سنة في إيجار';
        }

        $lines = [$line1];

        if ($years > 1) {
            $lines[] = "كل سنة إضافية {$extra} ريال";
        }

        $lines[] = 'إجمالي الرسوم شامل رسوم إيجار: '.number_format($fee).' ر.س';

        return $lines;
    }

    /**
     * نتيجة كاملة للـ API / المالية / الدفع
     *
     * @return array{
     *   duration_preset: string,
     *   duration_years: int|null,
     *   duration_months: int|null,
     *   total_months: int,
     *   billable_years: int,
     *   has_extra_months: bool,
     *   first_year_fee: float,
     *   extra_year_fee: float,
     *   doc_fee: float,
     *   doc_fee_lines: list<string>
     * }
     */
    public static function summarize(
        string $contractType,
        string $preset,
        int $years = 0,
        int $extraMonths = 0
    ): array {
        $totalMonths = self::resolveTotalMonths($preset, $years, $extraMonths);
        $hasExtra = self::hasExtraMonths($preset, $extraMonths);

        return [
            'duration_preset' => $preset,
            'duration_years' => $preset === 'other' ? $years : null,
            'duration_months' => $preset === 'other' ? $extraMonths : null,
            'total_months' => $totalMonths,
            'billable_years' => self::billableYears($totalMonths),
            'has_extra_months' => $hasExtra,
            'first_year_fee' => self::firstYearFee($contractType),
            'extra_year_fee' => self::extraYearFee($contractType),
            'doc_fee' => self::amount($totalMonths, $contractType),
            'doc_fee_lines' => $preset === 'other'
                ? self::lines($totalMonths, $contractType, $hasExtra)
                : [],
        ];
    }

    /**
     * Months implied by the selected legacy contract period (contract_term_in_years), or null
     * when the contract has no period at all.
     */
    public static function monthsFromContractPeriod(\App\Models\Contract $contract): ?int
    {
        if (empty($contract->contract_term_in_years)) {
            return null;
        }

        $row = $contract->relationLoaded('contractTermInYears')
            ? $contract->contractTermInYears
            : $contract->contractTermInYears()->first();

        // الأشهر الصريحة من جدول المدد (سنة = 12 / سنتين = 24) لها الأولوية على تحليل النص.
        if ($row !== null && isset($row->months) && (int) $row->months > 0) {
            return (int) $row->months;
        }

        $period = $row?->period;

        if ($period === null) {
            // Dangling id (period row deleted): still a chosen duration → one billable year.
            return 12;
        }

        $period = trim((string) $period);
        $months = EjarPlatformFee::monthsFromPeriod($period);
        if ($months !== null) {
            return $months;
        }

        // Labels such as "سنتين" / "3 سنوات" / "24 شهر": pick up an explicit number when present.
        if (preg_match('/(\d+)/u', $period, $m) === 1) {
            $n = (int) $m[1];
            if ($n > 0) {
                return str_contains($period, 'شهر') ? $n : $n * 12;
            }
        }

        if (str_contains($period, 'سنتين') || str_contains($period, 'سنتان')) {
            return 24;
        }

        return 12;
    }

    /**
     * من عقد محفوظ — يستخدم الحقول المخزّنة إن وُجدت.
     *
     * @return array<string, mixed>|null
     */
    public static function forContract(\App\Models\Contract $contract): ?array
    {
        $preset = $contract->duration_preset;

        if (! $preset && ! $contract->total_months) {
            // Standard wizard path: the website sends `contract_term_in_years` (a contract_periods
            // row such as شهري / ربع سنوي / نصف سنوي / سنوي) and NO duration_preset. Map the period
            // to months so the same DocFee rule (any part of a year = one year) prices it, instead
            // of falling back to the legacy `contract_periods.price` column that produced the
            // 849-vs-799 style conflicts. Unknown labels count as one year.
            $months = self::monthsFromContractPeriod($contract);
            if ($months === null) {
                return null;
            }

            return [
                'duration_preset' => null,
                'duration_years' => null,
                'duration_months' => null,
                'total_months' => $months,
                'billable_years' => self::billableYears($months),
                'has_extra_months' => false,
                'first_year_fee' => self::firstYearFee((string) $contract->contract_type),
                'extra_year_fee' => self::extraYearFee((string) $contract->contract_type),
                'doc_fee' => self::amount($months, (string) $contract->contract_type),
                'doc_fee_lines' => [],
            ];
        }

        if ($preset) {
            return self::summarize(
                (string) $contract->contract_type,
                (string) $preset,
                (int) ($contract->duration_years ?? 0),
                (int) ($contract->duration_months ?? 0),
            );
        }

        $totalMonths = (int) $contract->total_months;
        $hasExtra = ((int) ($contract->duration_months ?? 0)) > 0;
        $preset = $contract->duration_preset;
        $isOther = $preset === 'other' || ($preset === null && $hasExtra);

        return [
            'duration_preset' => $preset,
            'duration_years' => $contract->duration_years,
            'duration_months' => $contract->duration_months,
            'total_months' => $totalMonths,
            'billable_years' => self::billableYears($totalMonths),
            'has_extra_months' => $hasExtra,
            'first_year_fee' => self::firstYearFee((string) $contract->contract_type),
            'extra_year_fee' => self::extraYearFee((string) $contract->contract_type),
            'doc_fee' => self::amount($totalMonths, (string) $contract->contract_type),
            'doc_fee_lines' => $isOther
                ? self::lines($totalMonths, (string) $contract->contract_type, $hasExtra)
                : [],
        ];
    }
}
