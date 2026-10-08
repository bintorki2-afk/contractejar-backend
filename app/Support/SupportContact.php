<?php

namespace App\Support;

use App\Models\Setting;

/**
 * رقم دعم واتساب الرسمي (ف18): من الإعدادات (`whatsapp_contact` ثم `whatsapp`)،
 * والقيمة الاحتياطية في الكود هي الرقم الرسمي 0597500014 (دولي 966597500014).
 */
final class SupportContact
{
    public const DEFAULT_WHATSAPP = '966597500014';

    /** الرقم الدولي بالأرقام فقط (966XXXXXXXXX). */
    public static function whatsapp(?Setting $setting = null): string
    {
        $setting ??= self::setting();

        foreach ([$setting?->whatsapp_contact, $setting?->whatsapp] as $candidate) {
            $normalized = self::normalize($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return self::DEFAULT_WHATSAPP;
    }

    /** الصيغة المحلية للعرض (05XXXXXXXX). */
    public static function whatsappLocal(?Setting $setting = null): string
    {
        $international = self::whatsapp($setting);

        return str_starts_with($international, '966') ? '0'.substr($international, 3) : $international;
    }

    public static function whatsappLink(?Setting $setting = null, ?string $text = null): string
    {
        $url = 'https://wa.me/'.self::whatsapp($setting);

        return $text !== null && $text !== '' ? $url.'?text='.rawurlencode($text) : $url;
    }

    /**
     * يقبل 05XXXXXXXX / 5XXXXXXXX / 9665XXXXXXXX / +9665XXXXXXXX / 009665XXXXXXXX ويعيد 9665XXXXXXXX.
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00966')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '05') && strlen($digits) === 10) {
            $digits = '966'.substr($digits, 1);
        } elseif (str_starts_with($digits, '5') && strlen($digits) === 9) {
            $digits = '966'.$digits;
        }

        return preg_match('/^9665\d{8}$/', $digits) === 1 ? $digits : null;
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
