<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\Setting;

/**
 * رسوم المستندات الإضافية: مبلغ ثابت (افتراضياً 75 ر.س) يُحصَّل مرة واحدة لكل عقد
 * عندما يكون نوع وثيقة الملكية من الأنواع التي تتطلب إجراءات إضافية.
 */
final class DocumentSurcharge
{
    public const DEFAULT_FEE = 75.0;

    /** أنواع المستندات التي تُفرض عليها الرسوم (مفاتيح instrument_type). */
    public const INSTRUMENT_TYPES = [
        'old_handwritten',                                   // صك ملكية ورقي
        'property_ownership_owner_are_deceased',             // صك والمالك متوفى
        'property_ownership_owner_are_deceased_endowment',   // صك والمالك متوفى (وقف)
        'property_ownership_owner_is_endowment',             // صك والمالك وقف
        'property_ownership_owner_are_suspended',            // صك ومالك موقوف (يُعامل كالوقف)
        'sale_agreement',                                    // ورقة مبايعة
        'economic_cities_authority_suspended',               // وثيقة هيئة المدن الاقتصادية
        'strong_argument',                                   // حجة استحكام
    ];

    public static function appliesTo(?string $instrumentType): bool
    {
        if ($instrumentType === null || $instrumentType === '') {
            return false;
        }

        return in_array($instrumentType, self::INSTRUMENT_TYPES, true);
    }

    public static function fee(?Setting $setting = null): float
    {
        $setting ??= self::setting();
        $value = $setting?->document_surcharge_fee;

        if ($value === null || ! is_numeric($value)) {
            return self::DEFAULT_FEE;
        }

        return max(0.0, (float) $value);
    }

    /** المبلغ المستحق لهذا العقد (0 أو الرسوم). */
    public static function forContract(Contract $contract, ?Setting $setting = null): float
    {
        return self::appliesTo($contract->instrument_type) ? self::fee($setting) : 0.0;
    }

    public static function forInstrumentType(?string $instrumentType, ?Setting $setting = null): float
    {
        return self::appliesTo($instrumentType) ? self::fee($setting) : 0.0;
    }

    private static function setting(): ?Setting
    {
        try {
            return Setting::query()->first();
        } catch (\Throwable) {
            return null;
        }
    }
}
