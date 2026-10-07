<?php

namespace App\Support;

use App\Models\Contract;

/**
 * الرابط الذكي للطلب: `https://contractejar.com/r/{رقم الطلب}`.
 *
 * يُرسل للعميل (واتساب/إشعار). على الجوال يفتح التطبيق إن كان مثبّتاً
 * (Universal/App Links)، وإلا يفتح صفحة الطلب على الموقع بعد التحقق بالجوال.
 */
final class SmartLink
{
    public static function forOrder(string|int $orderNumber): string
    {
        return rtrim((string) config('app.frontend_url', 'https://contractejar.com'), '/').'/r/'.rawurlencode((string) $orderNumber);
    }

    public static function for(Contract $contract): string
    {
        return self::forOrder((string) ($contract->uuid ?: $contract->id));
    }
}
