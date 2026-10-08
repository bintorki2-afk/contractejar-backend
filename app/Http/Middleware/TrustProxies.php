<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * نثق فقط بقفزات وكيل المنصة (Railway) لا بأي X-Forwarded-For يرسله العميل. (WEBSITE-1)
 *
 * - TRUSTED_PROXIES (بيئة): قائمة IP/CIDR مفصولة بفواصل، أو "*" للسلوك القديم.
 * - الافتراضي: نطاقات الشبكات الخاصة/الداخلية (وكيل Railway الداخلي) + loopback.
 *   أي عنوان عام يتصل مباشرة لا يُوثق برأسه، فلا يمكن تزوير IP لتجاوز حدود الطلبات.
 * IP الزائر القادم من خادم الموقع يُقبل فقط مع السر المشترك (ResolveTrustedClientIp).
 */
class TrustProxies extends Middleware
{
    public const DEFAULT_PRIVATE_PROXIES = [
        '127.0.0.1', '::1',
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
        '100.64.0.0/10', // CGNAT — شائع في الشبكات الداخلية للمنصات
        'fc00::/7',      // IPv6 ULA (شبكة Railway الخاصة)
    ];

    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    protected function proxies()
    {
        $configured = trim((string) config('app.trusted_proxies', ''));

        if ($configured === '*') {
            return '*';
        }

        if ($configured !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $configured))));
        }

        return self::DEFAULT_PRIVATE_PROXIES;
    }
}
