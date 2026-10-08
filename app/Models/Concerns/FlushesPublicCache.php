<?php

namespace App\Models\Concerns;

use App\Support\PublicCache;

/**
 * يفرّغ كاش النقاط العامة (الأسعار/الإعدادات/المدد/الكوبون) عند أي حفظ أو حذف للموديل.
 */
trait FlushesPublicCache
{
    public static function bootFlushesPublicCache(): void
    {
        static::saved(static fn () => PublicCache::flush());
        static::deleted(static fn () => PublicCache::flush());
    }
}
