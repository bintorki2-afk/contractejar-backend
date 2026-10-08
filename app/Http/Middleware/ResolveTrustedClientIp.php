<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * خادم الموقع (Vercel) ينادي الـ API نيابةً عن الزائر، فيظهر كل الزوار بعنوان Vercel.
 * يمرّر الموقع IP الزائر في X-Forwarded-For؛ نقبله **فقط** إذا جاء مع السر المشترك
 * X-Forwarded-Client-Secret == TRUSTED_FORWARDER_SECRET. عندها يصبح IP الزائر هو
 * request->ip() فتُحتسب حدود الطلبات لكل زائر. بدون السر يُتجاهل الرأس (لا تزوير). (WEBSITE-1)
 */
class ResolveTrustedClientIp
{
    public const SECRET_HEADER = 'X-Forwarded-Client-Secret';

    public function handle(Request $request, Closure $next)
    {
        $secret = (string) config('app.trusted_forwarder_secret', '');
        $provided = (string) $request->headers->get(self::SECRET_HEADER, '');

        // لا يُمرَّر السر لأي طبقة لاحقة/سجلات.
        $request->headers->remove(self::SECRET_HEADER);

        if ($secret === '' || $provided === '' || ! hash_equals($secret, $provided)) {
            return $next($request);
        }

        $forwarded = (string) $request->headers->get('X-Forwarded-For', '');
        $visitor = trim(explode(',', $forwarded)[0] ?? '');

        if ($visitor !== '' && filter_var($visitor, FILTER_VALIDATE_IP) !== false) {
            $request->server->set('REMOTE_ADDR', $visitor);
            $request->headers->remove('X-Forwarded-For');
        }

        return $next($request);
    }
}
