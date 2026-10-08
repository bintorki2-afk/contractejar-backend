<?php

namespace App\Services\Seo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class SiteCrawler
{
    /** @var list<string> */
    protected array $allowedHosts = [];

    protected string $baseHost = '';

    protected string $origin = '';

    public function __construct(
        protected HtmlPageParser $parser
    ) {}

    /**
     * Breadth-first crawl of public HTML pages starting from the homepage and sitemap.
     *
     * @param  callable(string, int, int): void|null  $onProgress
     * @param  callable(): bool|null  $shouldStop
     * @return list<array<string, mixed>>
     */
    public function crawl(?string $baseUrl = null, ?int $maxPages = null, ?callable $onProgress = null, ?callable $shouldStop = null): array
    {
        $baseUrl = $this->normalizeUrl($baseUrl ?: (string) config('seo_crawl.base_url'));
        $maxPages = $maxPages ?? (int) config('seo_crawl.max_pages', 400);
        $delayMs = (int) config('seo_crawl.delay_ms', 80);
        if (app()->environment('testing')) {
            $delayMs = 0;
        }

        $this->origin = $this->origin($baseUrl);
        $this->allowedHosts = array_map('strtolower', (array) config('seo_crawl.allowed_hosts', []));
        $this->baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        if ($this->baseHost !== '' && ! in_array($this->baseHost, $this->allowedHosts, true)) {
            $this->allowedHosts[] = $this->baseHost;
        }

        $queue = [];
        $queued = [];
        $pages = [];
        $linkStatus = [];

        $enqueue = function (string $url) use (&$queue, &$queued): void {
            $normalized = $this->normalizeDiscoveredUrl($url);
            if ($normalized === null) {
                return;
            }
            $key = $this->urlKey($normalized);
            if (isset($queued[$key])) {
                return;
            }
            $queued[$key] = true;
            $queue[] = $normalized;
        };

        $seedUrls = array_values(array_unique([
            $baseUrl,
            ...(array) config('seo_crawl.seed_urls', []),
        ]));

        $origins = [];
        foreach ($seedUrls as $seedUrl) {
            if (! is_string($seedUrl) || trim($seedUrl) === '') {
                continue;
            }

            $seedUrl = $this->normalizeUrl($seedUrl);
            $enqueue($seedUrl);
            $origins[] = $this->origin($seedUrl);
        }

        foreach (array_unique($origins) as $origin) {
            foreach ($this->urlsFromSitemap($origin) as $loc) {
                $enqueue($loc);
            }
        }

        $this->abortIfStopped([], $shouldStop);

        $crawled = 0;
        while ($queue !== [] && $crawled < $maxPages) {
            $url = array_shift($queue);
            $crawled++;
            $this->abortIfStopped($pages, $shouldStop);
            if ($onProgress) {
                $onProgress($url, $crawled, $maxPages);
            }
            $this->abortIfStopped($pages, $shouldStop);

            $page = $this->fetchPage($url);
            $pages[] = $page;
            $linkStatus[$this->urlKey($url)] = (int) $page['status_code'];

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            if (! $page['is_html'] || (int) $page['status_code'] !== 200) {
                continue;
            }

            foreach ($page['outbound_urls'] as $href) {
                $enqueue($href);
            }
        }

        $unchecked = [];
        foreach ($pages as $page) {
            foreach ($page['outbound_urls'] as $href) {
                $normalized = $this->normalizeDiscoveredUrl($href);
                if ($normalized === null) {
                    continue;
                }
                $key = $this->urlKey($normalized);
                if (! isset($linkStatus[$key]) && ! isset($unchecked[$key])) {
                    $unchecked[$key] = $normalized;
                }
            }
        }

        foreach ($unchecked as $url) {
            if (count($pages) >= $maxPages) {
                break;
            }
            $this->abortIfStopped($pages, $shouldStop);
            if ($onProgress) {
                $onProgress($url, count($pages) + 1, $maxPages);
            }
            $this->abortIfStopped($pages, $shouldStop);
            $page = $this->fetchPage($url);
            $pages[] = $page;
            $linkStatus[$this->urlKey($url)] = (int) $page['status_code'];
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        foreach ($pages as $i => $page) {
            $broken = [];
            foreach ($page['outbound_urls'] as $href) {
                $normalized = $this->normalizeDiscoveredUrl($href);
                if ($normalized === null) {
                    continue;
                }
                $status = $linkStatus[$this->urlKey($normalized)] ?? null;
                if ($status !== null && $status >= 400) {
                    $broken[] = [
                        'url' => $normalized,
                        'path' => $this->displayPath($normalized),
                        'status_code' => $status,
                    ];
                }
            }
            $pages[$i]['broken_links'] = $broken;
        }

        $this->abortIfStopped($pages, $shouldStop);

        return $pages;
    }

    /**
     * نتيجة جلب مرفوضة (رابط داخلي/خاص — حماية SSRF): بنفس شكل صفحة فاشلة.
     *
     * @return array<string, mixed>
     */
    protected function blockedFetchResult(string $url): array
    {
        return [
            'url' => $url,
            'path' => $this->displayPath($url),
            'status_code' => 0,
            'load_time_ms' => 0,
            'content_type' => null,
            'title' => null,
            'meta_description' => null,
            'h1s' => [],
            'h1_count' => 0,
            'image_count' => 0,
            'images_missing_alt' => 0,
            'outbound_urls' => [],
            'is_html' => false,
            'is_indexable' => false,
            'failed' => true,
            'broken_links' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @param  callable(): bool|null  $shouldStop
     */
    protected function abortIfStopped(array $pages, ?callable $shouldStop): void
    {
        if ($shouldStop && $shouldStop()) {
            throw new SeoCrawlStoppedException($pages);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchPage(string $url): array
    {
        $started = microtime(true);
        $status = 0;
        $contentType = null;
        $body = '';
        $noindexHeader = false;
        $failed = false;

        // حماية SSRF: لا نجلب رابطاً يشير لعنوان داخلي/خاص (الرابط قد يكون من إدخال المستخدم).
        if (! \App\Support\OutboundUrlGuard::isPubliclyFetchable($url)) {
            return $this->blockedFetchResult($url);
        }

        try {
            $response = Http::timeout((int) config('seo_crawl.timeout_seconds', 20))
                ->withHeaders([
                    'User-Agent' => (string) config('seo_crawl.user_agent'),
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'ar,en;q=0.8',
                ])
                ->withOptions(['http_errors' => false])
                ->get($url);

            $status = $response->status();
            $contentType = $this->headerValue($response, 'Content-Type');
            $robotsHeader = strtolower((string) $this->headerValue($response, 'X-Robots-Tag'));
            $noindexHeader = str_contains($robotsHeader, 'noindex');
            $body = (string) $response->body();
        } catch (ConnectionException $e) {
            $failed = true;
        } catch (Throwable $e) {
            $failed = true;
        }

        $loadTimeMs = (int) round((microtime(true) - $started) * 1000);
        $isHtml = $this->looksLikeHtml($contentType, $body);
        $parsed = $isHtml
            ? $this->parser->parse($body, $url)
            : [
                'title' => null,
                'description' => null,
                'h1s' => [],
                'image_count' => 0,
                'images_missing_alt' => 0,
                'hrefs' => [],
                'noindex' => false,
                'canonical' => null,
            ];

        $internalHrefs = [];
        foreach ($parsed['hrefs'] as $href) {
            $canonical = $this->normalizeDiscoveredUrl($href);
            if ($canonical !== null) {
                $internalHrefs[] = $canonical;
            }
        }

        return [
            'url' => $url,
            'path' => $this->displayPath($url),
            'status_code' => $status,
            'load_time_ms' => $loadTimeMs,
            'content_type' => $contentType,
            'title' => $parsed['title'],
            'meta_description' => $parsed['description'],
            'h1s' => $parsed['h1s'],
            'h1_count' => count($parsed['h1s']),
            'image_count' => $parsed['image_count'],
            'images_missing_alt' => $parsed['images_missing_alt'],
            'outbound_urls' => array_values(array_unique($internalHrefs)),
            'is_html' => $isHtml,
            'is_indexable' => $isHtml && $status === 200 && ! $parsed['noindex'] && ! $noindexHeader,
            'failed' => $failed || $status === 0,
            'broken_links' => [],
        ];
    }

    /**
     * @return list<string>
     */
    protected function urlsFromSitemap(string $origin): array
    {
        $sitemapUrl = rtrim($origin, '/').'/sitemap.xml';

        // حماية SSRF: لا نجلب sitemap من عنوان داخلي/خاص.
        if (! \App\Support\OutboundUrlGuard::isPubliclyFetchable($sitemapUrl)) {
            return [];
        }

        try {
            $response = Http::timeout((int) config('seo_crawl.timeout_seconds', 20))
                ->withHeaders(['User-Agent' => (string) config('seo_crawl.user_agent')])
                ->withOptions(['http_errors' => false])
                ->get($sitemapUrl);
        } catch (Throwable $e) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $xml = $response->body();
        if (! preg_match_all('#<loc>\s*([^<]+)\s*</loc>#i', $xml, $matches)) {
            return [];
        }

        return array_values(array_unique(array_map('trim', $matches[1])));
    }

    public function normalizeDiscoveredUrl(string $url): ?string
    {
        $url = $this->stripFragment($url);
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        if ($this->allowedHosts !== [] && ! in_array($host, $this->allowedHosts, true)) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }

        if ($this->shouldSkipPath($path)) {
            return null;
        }

        $canonicalHosts = (array) config('seo_crawl.canonical_hosts', []);
        $rewriteHost = strtolower((string) ($canonicalHosts[$host] ?? $host));
        $scheme = strtolower($parts['scheme']) === 'http' ? 'https' : strtolower($parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return null;
        }

        $normalizedPath = $path === '/' ? '/' : rtrim($path, '/');

        return $scheme.'://'.$rewriteHost.$normalizedPath;
    }

    protected function shouldSkipPath(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, (array) config('seo_crawl.skip_extensions', []), true)) {
            return true;
        }

        foreach ((array) config('seo_crawl.exclude_path_regex', []) as $pattern) {
            if (is_string($pattern) && preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeHtml(?string $contentType, string $body): bool
    {
        $type = strtolower((string) $contentType);
        if ($type !== '' && (str_contains($type, 'text/html') || str_contains($type, 'application/xhtml'))) {
            return true;
        }
        if ($type !== '' && ! str_contains($type, 'text/plain')) {
            return false;
        }

        return str_contains(strtolower(substr($body, 0, 512)), '<html')
            || str_contains(strtolower(substr($body, 0, 512)), '<!doctype html');
    }

    protected function headerValue(Response $response, string $name): ?string
    {
        $value = $response->header($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function displayPath(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = parse_url($url, PHP_URL_PATH);
        $isSubdomain = $host !== ''
            && $this->baseHost !== ''
            && $host !== $this->baseHost
            && $host !== 'www.'.$this->baseHost;

        if (! is_string($path) || $path === '' || $path === '/') {
            return $isSubdomain ? $host.'/' : '/';
        }

        $path = ltrim($path, '/');
        if ($isSubdomain) {
            $path = $host.'/'.$path;
        }

        return str_ends_with($path, '/') ? $path : $path.'/';
    }

    public function urlKey(string $url): string
    {
        return sha1($this->stripFragment($url));
    }

    public function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'aqdi.sa';

        return $scheme.'://'.$host;
    }

    public function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        return rtrim($url, '/');
    }

    protected function stripFragment(string $url): string
    {
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }

        $query = strpos($url, '?');
        if ($query !== false) {
            $url = substr($url, 0, $query);
        }

        return $url;
    }
}
