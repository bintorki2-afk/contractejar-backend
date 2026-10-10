<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use \App\Models\Concerns\FlushesPublicCache;
    use HasFactory;

    protected $fillable = [
        'whatsapp',
        'instagram',
        'twitter',
        'snapchat',
        'facebook',
        'tiktok',
        'linkedIn',
        'whatsapp_contact',
        'whatsapp_contract',
        // دفعة (هـ)
        'bank_name',
        'bank_iban',
        'bank_account_name',
        'housing_tax',
        'commercial_tax',
        'vat_rate',
        'application_fees',
        'open_payment',
        'is_open',
        'working_hours',
        'version',
        'time_to_documentation_contract',
        'text_message_user',
        'text_message_admin',
        'sms_user',
        'sms_owner',
        'sms_employee',
        'electricity_meter_fee_commercial_tenant',
        'electricity_meter_fee_housing_tenant',
        'water_meter_fee_commercial_tenant',
        'water_meter_fee_housing_tenant',
        'cover',
        'banner',
        'moyasar_fee_percent',
        'moyasar_mada_percent',
        'moyasar_credit_percent',
        'moyasar_fixed_fee',
        'monthly_salaries',
        'operating_budget',
        'marketing_budget',
        'meter_transfer_fee',
        'doc_fee_housing_first_year',
        'doc_fee_housing_extra_year',
        'doc_fee_commercial_first_year',
        'doc_fee_commercial_extra_year',
        'document_surcharge_fee',
        'lessor_change_fee',
        // دفعة (د) — ب13: الإسناد التلقائي
        'auto_assign_orders',
        'auto_assign_strategy',
        'auto_assign_employee_ids',
        // دفعة (و) — D9/D7
        'pay_after_draft_enabled',
        'working_hours_en',
        'reviews_enabled',
        'reviews_average',
        'reviews_count',
    ];

    /** دفعة (و) — D8: ساعات العمل الافتراضية (قرار المالك 2026-10-10). */
    public const DEFAULT_WORKING_HOURS = 'يومياً من 12 ظهراً حتى 12 منتصف الليل، والجمعة من 3 عصراً حتى 12 منتصف الليل';

    /**
     * ساعات العمل المنظّمة (Schema.org OpeningHoursSpecification) — تتبع النص الافتراضي.
     *
     * @return list<array{days: list<string>, opens: string, closes: string}>
     */
    public static function openingHours(): array
    {
        return [
            ['days' => ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'], 'opens' => '12:00', 'closes' => '23:59'],
            ['days' => ['Friday'], 'opens' => '15:00', 'closes' => '23:59'],
        ];
    }

    public static function workingHoursText(?self $setting = null): string
    {
        $setting ??= static::query()->first();
        $text = trim((string) ($setting?->working_hours ?? ''));

        return $text !== '' ? $text : self::DEFAULT_WORKING_HOURS;
    }

    public static function workingHoursTextEn(?self $setting = null): string
    {
        $setting ??= static::query()->first();
        $text = trim((string) ($setting?->working_hours_en ?? ''));

        return $text !== '' ? $text : \App\Support\WorkingHoursText::EN;
    }

    /** دفعة (و) — D9. */
    public static function payAfterDraftEnabled(?self $setting = null): bool
    {
        try {
            $setting ??= static::query()->first();
        } catch (\Throwable) {
            return false;
        }

        return (bool) ($setting?->pay_after_draft_enabled ?? false);
    }

    /**
     * دفعة (و) — D7: ملخص التقييمات المعروض («4.7 من 3000»).
     *
     * @return array{enabled: bool, average: float, count: int, label: string}
     */
    public static function reviewsSummary(?self $setting = null): array
    {
        try {
            $setting ??= static::query()->first();
        } catch (\Throwable) {
            $setting = null;
        }
        $average = $setting !== null && is_numeric($setting->reviews_average ?? null) ? round((float) $setting->reviews_average, 1) : 4.7;
        $count = $setting !== null && is_numeric($setting->reviews_count ?? null) ? (int) $setting->reviews_count : 3000;
        $enabled = $setting !== null && $setting->reviews_enabled !== null ? (bool) $setting->reviews_enabled : true;
        $avgLabel = rtrim(rtrim(number_format($average, 1, '.', ''), '0'), '.');

        return [
            'enabled' => $enabled,
            'average' => $average,
            'count' => $count,
            'label' => $avgLabel.' من 5 · أكثر من '.$count.' تقييم',
        ];
    }

    protected $casts = [
        'pay_after_draft_enabled' => 'boolean',
        'reviews_enabled' => 'boolean',
        'auto_assign_orders' => 'boolean',
        'auto_assign_employee_ids' => 'array',
    ];
}
