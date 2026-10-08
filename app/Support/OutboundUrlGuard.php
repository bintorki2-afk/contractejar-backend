<?php

namespace App\Support;

/**
 * حارس الطلبات الخارجية (SSRF): يمنع جلب روابط تشير إلى عناوين داخلية/خاصة.
 *
 * يُستخدم قبل أي طلب HTTP صادر إلى رابط يتحكم به مستخدم (زحف SEO مثلاً) حتى لا
 * يُستغل الخادم لقراءة خدمات داخلية أو بيانات اعتماد السحابة (169.254.169.254).
 */
final class OutboundUrlGuard
{
    /**
     * هل الرابط آمن للجلب؟ (مخطط http/https + مضيف لا يحل إلى عنوان خاص/محجوز).
     */
    public static function isPubliclyFetchable(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === '') {
            return false;
        }

        // رفض أسماء داخلية شائعة لا تحمل نقطة (localhost) و.local/.internal.
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        $ips = self::resolveIps($host);
        if ($ips === []) {
            // تعذّر الحل: نرفض احتياطاً (قد يكون مضيفاً داخلياً بلا DNS عام).
            return false;
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function resolveIps(string $host): array
    {
        // عنوان IP مباشر.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $a = @gethostbynamel($host);
        if (is_array($a)) {
            $ips = array_merge($ips, $a);
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (! empty($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private static function isPublicIp(string $ip): bool
    {
        // يرفض النطاقات الخاصة والمحجوزة (loopback, link-local, private, …) لـ IPv4 و IPv6.
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
