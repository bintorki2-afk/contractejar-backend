<?php

namespace App\Support;

final class HijriDobParts
{
    /** Stored format: DD-MM-YYYY (يوم-شهر-سنة). */
    public static function combine(string|int $day, string|int $month, string|int $year): string
    {
        $d = str_pad((string) (int) $day, 2, '0', STR_PAD_LEFT);
        $m = str_pad((string) (int) $month, 2, '0', STR_PAD_LEFT);
        $y = preg_replace('/\D/', '', (string) $year);

        return "{$d}-{$m}-{$y}";
    }

    /**
     * @return array{day: ?string, month: ?string, year: ?string} Zero-padded day/month for UI dropdowns.
     */
    public static function split(?string $value): array
    {
        if ($value === null || $value === '') {
            return ['day' => null, 'month' => null, 'year' => null];
        }

        $parts = preg_split('/[-\/]/', trim($value));
        if (count($parts) !== 3) {
            return ['day' => null, 'month' => null, 'year' => null];
        }

        // دفعة (د): التاريخ الميلادي يُخزَّن YYYY-MM-DD (تاريخ بداية العقد) — كان يُقسم بالعكس (اليوم = 2026).
        if (strlen(preg_replace('/\D/', '', $parts[0]) ?? '') === 4) {
            return [
                'day' => str_pad((string) (int) $parts[2], 2, '0', STR_PAD_LEFT),
                'month' => str_pad((string) (int) $parts[1], 2, '0', STR_PAD_LEFT),
                'year' => (string) preg_replace('/\D/', '', $parts[0]),
            ];
        }

        return [
            'day' => str_pad((string) (int) $parts[0], 2, '0', STR_PAD_LEFT),
            'month' => str_pad((string) (int) $parts[1], 2, '0', STR_PAD_LEFT),
            'year' => (string) preg_replace('/\D/', '', $parts[2]),
        ];
    }
}
