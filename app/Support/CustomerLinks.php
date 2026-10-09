<?php

namespace App\Support;

use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Facades\URL;

/**
 * روابط مطلقة تُخزَّن أو تُرسَل للعميل (رابط دفع رسم، الفاتورة، إيصال الحوالة) — دفعة (هـ) B-6.
 *
 * تُبنى دائماً على جذر ثابت من الإعدادات (`APP_URL`) لا على مضيف الطلب الحالي، لأن الطلب قد يصل عبر
 * proxy اللوحة (X-Forwarded-Host = نطاق اللوحة) أو عبر رأس مزوّر، فيُخزَّن في الإشعار رابط بمضيف خاطئ.
 */
final class CustomerLinks
{
    /** الجذر الثابت للـ API (بلا شرطة أخيرة)، أو null إن لم يُضبط APP_URL. */
    public static function apiRoot(): ?string
    {
        $root = rtrim(trim((string) config('app.url', '')), '/');

        return $root !== '' ? $root : null;
    }

    /** جذر الموقع (FRONTEND_URL) للروابط العميقة. */
    public static function frontendRoot(): string
    {
        return rtrim((string) config('app.frontend_url', 'https://contractejar.com'), '/');
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function route(string $name, array $parameters = []): string
    {
        return self::withApiRoot(static fn (): string => route($name, $parameters));
    }

    /**
     * @param  DateTimeInterface|DateInterval|int  $expiration
     * @param  array<string, mixed>  $parameters
     */
    public static function temporarySignedRoute(string $name, DateTimeInterface|DateInterval|int $expiration, array $parameters = []): string
    {
        return self::withApiRoot(static fn (): string => URL::temporarySignedRoute($name, $expiration, $parameters));
    }

    /**
     * ينفّذ المولّد والجذر مفروض على APP_URL ثم يعيد الحالة السابقة (لا جذر مفروض).
     *
     * @template T
     *
     * @param  callable(): T  $generate
     * @return T
     */
    public static function withApiRoot(callable $generate): mixed
    {
        $root = self::apiRoot();
        if ($root === null) {
            return $generate();
        }

        $url = app('url');
        $url->forceRootUrl($root);
        $url->forceScheme(str_starts_with($root, 'https://') ? 'https' : 'http');
        try {
            return $generate();
        } finally {
            $url->forceRootUrl(null);
            $url->forceScheme(null);
        }
    }
}
