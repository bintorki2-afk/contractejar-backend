<?php

namespace App\Services\Seo;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * فحص ما بعد النقل: كل رابط كان يجيب ترافيك لازم يرجع 200 مباشرة، أو 301/308 بقفزة وحدة
 * لصفحة ترجع 200. أي 404 / 5xx / تحويل مؤقت / سلسلة تحويلات / Location غير مرمّز = فشل.
 *
 * وضع التجربة قبل تغيير DNS: --ip=IP_السيرفر يوجّه الدومين للسيرفر الجديد (مثل curl --resolve)
 * بنفس الرابط والـ https، فنفحص السيرفر الجديد والموقع القديم شغال.
 */
class UrlMigrationVerifier
{
    public const OK_200 = 'ok_200';
    public const OK_301 = 'ok_301';

    /**
     * @param  list<string>  $allowedHosts  الدومينات المقبولة كوجهة نهائية
     */
    /**
     * @param  ?string  $ip  وضع ما قبل DNS: يوجّه الدومين لهذا الـ IP (مثل --resolve في curl) مع الإبقاء
     *                       على الرابط والـ https وشهادة الدومين كما هي
     * @param  array<string, string>  $expectedTargets  source_url => target_url من redirect-map.csv
     */
    public function __construct(
        private readonly ?string $ip = null,
        private readonly array $allowedHosts = ['aqdi.sa'],
        private readonly int $timeout = 20,
        private readonly array $expectedTargets = [],
    ) {
    }

    /** @return array<string, string> */
    public function check(string $url): array
    {
        $line = [
            'url' => $url,
            'result' => '',
            'status' => '',
            'location' => '',
            'final_status' => '',
            'canonical' => '',
            'note' => '',
        ];

        try {
            $first = $this->request($url);
        } catch (\Throwable $e) {
            return ['result' => 'fail_network', 'note' => mb_substr($e->getMessage(), 0, 200)] + $line;
        }

        $status = $first->status();
        $line['status'] = (string) $status;

        if ($status === 200) {
            $line['canonical'] = $this->canonical($first);

            return ['result' => self::OK_200, 'note' => $this->canonicalNote($url, $line['canonical'])] + $line;
        }

        if (! in_array($status, [301, 302, 303, 307, 308], true)) {
            return ['result' => $status === 404 || $status === 410 ? 'fail_not_found' : 'fail_status_'.$status] + $line;
        }

        $location = (string) $first->header('Location');
        $line['location'] = $location;

        if (in_array($status, [302, 303, 307], true)) {
            return ['result' => 'fail_temporary_redirect'] + $line;
        }
        if ($location === '') {
            return ['result' => 'fail_redirect_without_location'] + $line;
        }
        if (preg_match('/[^\x21-\x7E]/', $location)) {
            return ['result' => 'fail_non_ascii_location', 'note' => 'Location must be percent-encoded'] + $line;
        }

        $target = $this->resolve($url, $location);
        $host = strtolower((string) parse_url($target, PHP_URL_HOST));
        if (! in_array($host, $this->allowedHosts, true)) {
            return ['result' => 'fail_redirect_to_foreign_host', 'note' => $host] + $line;
        }

        // تحويل مقال للرئيسية/لقائمة = المقال ضاع من قوقل حتى لو الصفحة ترجع 200
        $sourcePath = (string) parse_url($url, PHP_URL_PATH);
        $targetPath = (string) parse_url($target, PHP_URL_PATH);
        if (trim($sourcePath, '/') !== '' && in_array(rtrim($targetPath, '/'), ['', '/blog', '/blogs'], true)) {
            return ['result' => 'fail_redirect_to_home_or_listing', 'note' => $target] + $line;
        }
        if (isset($this->expectedTargets[$url]) && $this->sameUrl($this->expectedTargets[$url], $target) === false) {
            return ['result' => 'fail_wrong_target', 'note' => 'expected '.$this->expectedTargets[$url]] + $line;
        }

        try {
            $second = $this->request($target);
        } catch (\Throwable $e) {
            return ['result' => 'fail_network_target', 'note' => mb_substr($e->getMessage(), 0, 200)] + $line;
        }

        $line['final_status'] = (string) $second->status();
        if ($second->status() === 200) {
            $line['canonical'] = $this->canonical($second);

            return ['result' => self::OK_301, 'note' => $this->canonicalNote($target, $line['canonical'])] + $line;
        }
        if ($second->status() >= 300 && $second->status() < 400) {
            return ['result' => 'fail_redirect_chain', 'note' => 'second hop → '.(string) $second->header('Location')] + $line;
        }

        return ['result' => 'fail_target_status_'.$second->status()] + $line;
    }

    public static function passed(array $line): bool
    {
        return in_array($line['result'] ?? '', [self::OK_200, self::OK_301], true);
    }

    private function request(string $url): Response
    {
        $options = ['allow_redirects' => false, 'http_errors' => false];

        if ($this->ip !== null) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $options['curl'] = [CURLOPT_RESOLVE => [$host.':443:'.$this->ip, $host.':80:'.$this->ip]];
        }

        return Http::withHeaders(['User-Agent' => 'AqdiMigrationCheck/1.0 (+https://aqdi.sa)'])
            ->withOptions($options)
            ->timeout($this->timeout)
            ->get($url);
    }

    /** حل الـ Location حسب RFC 3986 (مطلق، //host، /مسار، أو نسبي للمجلد الحالي). */
    private function resolve(string $base, string $location): string
    {
        $p = parse_url($base);
        $scheme = $p['scheme'] ?? 'https';

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }
        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        $root = $scheme.'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '');
        if (str_starts_with($location, '/')) {
            return $root.$location;
        }

        $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');

        return $root.$dir.$location;
    }

    private function sameUrl(string $a, string $b): bool
    {
        $n = fn (string $u) => rtrim(rawurldecode((string) preg_replace('/[?#].*$/', '', $u)), '/');

        return $n($a) === $n($b);
    }

    private function canonical(Response $response): string
    {
        $type = strtolower((string) $response->header('Content-Type'));
        if ($type !== '' && ! str_contains($type, 'html')) {
            return '';
        }
        $body = (string) $response->body();
        if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]*>/i', $body, $m) && preg_match('/href=["\']([^"\']+)["\']/i', $m[0], $h)) {
            return html_entity_decode($h[1]);
        }

        return '';
    }

    private function canonicalNote(string $url, string $canonical): string
    {
        if ($canonical === '') {
            return '';
        }
        $norm = fn (string $u) => rtrim(rawurldecode((string) preg_replace('/[?#].*$/', '', $u)), '/');

        return $norm($canonical) === $norm($url) ? '' : 'canonical_points_elsewhere';
    }
}
