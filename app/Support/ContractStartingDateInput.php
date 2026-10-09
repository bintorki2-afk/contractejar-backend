<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Normalizes contract start date from API/website requests (Hijri parts, DD-MM-YYYY, YYYY-MM-DD, or strtotime).
 */
final class ContractStartingDateInput
{
    /**
     * Split DD-MM-YYYY / DD/MM/YYYY from contract_starting_date into day/month/year when those parts are absent.
     * Leaves YYYY-MM-DD on contract_starting_date only (Gregorian / legacy DB).
     */
    public static function prepareRequest(Request $request): void
    {
        if ($request->filled('contract_starting_date_day')) {
            return;
        }
        if (! $request->filled('contract_starting_date')) {
            return;
        }
        $raw = trim((string) $request->input('contract_starting_date'));
        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $raw)) {
            return;
        }
        $parts = preg_split('/[-\/]/', $raw);
        if (count($parts) !== 3) {
            return;
        }
        $request->merge([
            'contract_starting_date_day' => (int) $parts[0],
            'contract_starting_date_month' => (int) $parts[1],
            'contract_starting_date_year' => (int) $parts[2],
        ]);
    }

    public static function resolveForStorage(Request $request): string
    {
        $type = $request->input('type_contract_starting_date', 'hijri');

        if ($request->filled('contract_starting_date_day')
            && $request->filled('contract_starting_date_month')
            && $request->filled('contract_starting_date_year')) {
            if ($type === 'gregorian') {
                $mysql = DateInputNormalizer::combineFromParts(
                    $request->input('contract_starting_date_day'),
                    $request->input('contract_starting_date_month'),
                    $request->input('contract_starting_date_year'),
                );

                return $mysql ?? '';
            }

            return HijriDobParts::combine(
                $request->input('contract_starting_date_day'),
                $request->input('contract_starting_date_month'),
                $request->input('contract_starting_date_year'),
            );
        }

        return self::normalizeRawString(trim((string) $request->input('contract_starting_date', '')));
    }

    public static function normalizeRawString(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        $parts = preg_split('/[-\/]/', $raw);
        if (count($parts) === 3) {
            return HijriDobParts::combine($parts[0], $parts[1], $parts[2]);
        }
        $ts = strtotime($raw);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }

        return $raw;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function validationErrors(Request $request): array
    {
        $errors = [];
        $hasD = $request->filled('contract_starting_date_day');
        $hasM = $request->filled('contract_starting_date_month');
        $hasY = $request->filled('contract_starting_date_year');
        $partsCount = (int) $hasD + (int) $hasM + (int) $hasY;
        $raw = trim((string) $request->input('contract_starting_date', ''));

        if ($partsCount > 0 && $partsCount < 3) {
            $errors['contract_starting_date'] = ['أدخل يوم وشهر وسنة بداية العقد كاملة.'];
        }

        if ($partsCount === 0 && $raw === '') {
            $errors['contract_starting_date'] = ['تاريخ بداية العقد مطلوب.'];
        }

        return $errors;
    }

    /**
     * متابعة دفعة (د) — QA: صيغة التخزين: ميلادي `YYYY-MM-DD`، هجري `DD-MM-YYYY` (يحدّدها type_contract_starting_date).
     * للعرض/النسخ نرجع القيمتين بصيغة ثابتة: gregorian = YYYY-MM-DD، hijri = DD-MM-YYYY (أم القرى).
     *
     * @return array{contract_starting_date_gregorian: string|null, contract_starting_date_hijri: string|null}
     */
    public static function bothCalendars(?string $stored, ?string $type): array
    {
        $none = ['contract_starting_date_gregorian' => null, 'contract_starting_date_hijri' => null];
        $raw = trim((string) $stored);
        if ($raw === '') {
            return $none;
        }

        try {
            $hijri = HijriDate::parseStored($raw);
            if ($hijri !== null && ($type === 'hijri' || $type === null || $type === '')) {
                [$y, $m, $d] = $hijri;

                return [
                    'contract_starting_date_gregorian' => HijriDate::toGregorian($y, $m, $d)->format('Y-m-d'),
                    'contract_starting_date_hijri' => sprintf('%02d-%02d-%04d', $d, $m, $y),
                ];
            }

            $greg = \Illuminate\Support\Carbon::parse($raw);
            [$hy, $hm, $hd] = HijriDate::fromGregorian($greg);

            return [
                'contract_starting_date_gregorian' => $greg->format('Y-m-d'),
                'contract_starting_date_hijri' => sprintf('%02d-%02d-%04d', $hd, $hm, $hy),
            ];
        } catch (\Throwable) {
            return $none;
        }
    }
}
