<?php

namespace App\Support;

/**
 * QA-F PROPS-6: استخراج الإحداثيات من رابط قوقل ماب، وكشف الإحداثي الافتراضي (وسط الرياض)
 * الذي ترسله الواجهة كقيمة مبدئية — لا نحفظ موقعاً ملفّقاً.
 */
final class MapUrlCoordinates
{
    /** القيمة المبدئية في معالج العقار (الموقع) — ليست موقع العقار. */
    public const PLACEHOLDER = [24.7136, 46.6753];

    /** @return array{0: float, 1: float}|null */
    public static function fromUrl(?string $url): ?array
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        $decoded = urldecode($url);
        $patterns = [
            '/@(-?\d{1,2}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)/',
            '/[?&](?:q|query|ll|destination|daddr|center)=(-?\d{1,2}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)/',
            '/!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $decoded, $m) === 1) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
                if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                    return [$lat, $lng];
                }
            }
        }

        return null;
    }

    public static function isPlaceholder(mixed $lat, mixed $lng): bool
    {
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return false;
        }

        return abs((float) $lat - self::PLACEHOLDER[0]) < 0.00001 && abs((float) $lng - self::PLACEHOLDER[1]) < 0.00001;
    }
}
