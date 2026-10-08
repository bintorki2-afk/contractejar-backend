<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * كاش النقاط العامة الخفيفة (#27): الأسعار، الإعدادات، مدد العقد، توفر الكوبون.
 * 10 دقائق، ويُفرَّغ عند حفظ الإعدادات / تعديل المدد / تعديل الكوبونات.
 */
final class PublicCache
{
    public const TTL_SECONDS = 600;

    /** Cache-Control للنقاط العامة (المتصفح/CDN: 5 دقائق). */
    public const CACHE_CONTROL = 'public, max-age=300';

    public const KEY_PRICING = 'public.pricing';

    public const KEY_SETTINGS = 'public.settings';

    public const KEY_CONTRACT_PERIODS = 'public.contract-periods';

    public const KEY_COUPON_AVAILABLE = 'public.coupons.available';

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $callback
     * @return TValue
     */
    public static function remember(string $key, callable $callback): mixed
    {
        try {
            return Cache::remember(self::fullKey($key), self::TTL_SECONDS, $callback);
        } catch (\Throwable) {
            // الكاش غير متاح: نحسب القيمة مباشرة.
            return $callback();
        }
    }

    public static function forget(string $key): void
    {
        try {
            Cache::forget(self::fullKey($key));
        } catch (\Throwable) {
        }
    }

    /** تفريغ كل مفاتيح النقاط العامة (يُستدعى من حفظ الإعدادات/المدد/الكوبونات). */
    public static function flush(): void
    {
        foreach ([self::KEY_PRICING, self::KEY_SETTINGS, self::KEY_CONTRACT_PERIODS, self::KEY_COUPON_AVAILABLE] as $key) {
            self::forget($key);
        }

        // مدد العقد مخزّنة لكل نوع ولغة في CatalogLookupController.
        foreach (['housing', 'commercial'] as $type) {
            foreach (['ar', 'en'] as $locale) {
                try {
                    Cache::forget('catalog.lookup.contract-periods.v2.'.$type.'.'.$locale);
                } catch (\Throwable) {
                }
            }
        }
    }

    private static function fullKey(string $key): string
    {
        return $key.'.'.app()->getLocale();
    }
}
