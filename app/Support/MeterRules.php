<?php

namespace App\Support;

use Illuminate\Validation\Validator;

/**
 * قواعد العدادات لكل وحدة:
 *  إذا فُعّل عداد (كهرباء/ماء) → رقم العداد إجباري + اختيار «باسم المالك / باسم المستأجر / مشترك» إجباري،
 *  وإذا كان مشتركاً → مبلغ شهري أكبر من صفر إجباري.
 */
final class MeterRules
{
    public const OWNERSHIPS = ['owner', 'tenant', 'shared'];

    /** @param  list<array<string, mixed>>  $units */
    public static function validateUnits(array $units, Validator $validator, string $prefix = 'units'): void
    {
        foreach ($units as $index => $unit) {
            if (! is_array($unit)) {
                continue;
            }
            foreach (['electricity' => 'الكهرباء', 'water' => 'الماء'] as $kind => $label) {
                $enabled = filter_var($unit[$kind.'_meter'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if (! $enabled) {
                    continue;
                }

                $key = "{$prefix}.{$index}.";
                $number = trim((string) ($unit[$kind.'_meter_number'] ?? ''));
                $ownership = $unit[$kind.'_meter_ownership'] ?? null;
                $sharedFee = $unit[$kind.'_shared_monthly_fee'] ?? null;

                if ($number === '') {
                    $validator->errors()->add($key.$kind.'_meter_number', "رقم عداد {$label} مطلوب عند تفعيل العداد.");
                }
                if (! in_array($ownership, self::OWNERSHIPS, true)) {
                    $validator->errors()->add($key.$kind.'_meter_ownership', "اختر باسم من يكون عداد {$label}: المالك، المستأجر، أو مشترك.");
                }
                if ($ownership === 'shared' && (! is_numeric($sharedFee) || (float) $sharedFee <= 0)) {
                    $validator->errors()->add($key.$kind.'_shared_monthly_fee', "أدخل قيمة رسوم عداد {$label} المشترك الشهرية على المستأجر.");
                }
            }
        }
    }
}
