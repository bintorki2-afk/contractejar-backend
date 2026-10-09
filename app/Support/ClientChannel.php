<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * قناة العميل للطلب (متابعة دفعة د — QA): web | app.
 * الموقع يرسل `X-Client: website` (و app_or_web=web)؛ التطبيق `X-Client: app` (أو بلا ترويسة — التطبيق القديم).
 * جلسة الزائر (POST /auth/guest) خاصة بالموقع ⇒ web.
 */
final class ClientChannel
{
    public static function fromRequest(Request $request): string
    {
        $raw = strtolower(trim((string) ($request->header('X-Client') ?? '')));
        if (in_array($raw, ['website', 'web', 'site', 'browser'], true)) {
            return 'web';
        }
        if (in_array($raw, ['app', 'ios', 'android', 'mobile', 'flutter'], true)) {
            return 'app';
        }

        $explicit = strtolower(trim((string) ($request->input('app_or_web') ?? $request->input('platform') ?? '')));
        if (in_array($explicit, ['web', 'website'], true)) {
            return 'web';
        }
        if (in_array($explicit, ['app', 'ios', 'android'], true)) {
            return 'app';
        }

        $user = $request->user();
        if ($user !== null && (bool) ($user->is_guest ?? false)) {
            return 'web';
        }

        return 'app';
    }
}
