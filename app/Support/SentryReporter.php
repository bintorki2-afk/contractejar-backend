<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends unhandled exceptions to Sentry over the raw ingest (envelope) API, so
 * no composer package / build change is required. Wrapped in try/catch so
 * reporting can never break the request itself.
 *
 * فحص (CROSS-12):
 *  - الـ DSN من البيئة فقط (SENTRY_DSN)؛ فارغ = الإرسال معطّل (لا يذهب خطأ تطوير لمشروع الإنتاج).
 *  - لا بيانات شخصية (مكافئ send_default_pii=false): لا مستخدم ولا IP ولا ترويسات، ورسالة
 *    الخطأ والرابط يُنقّيان من الأرقام الطويلة (جوالات/هويات) والبريد والتوكنات، والرابط بلا query.
 *  - الإرسال بعد الاستجابة (لا يحجز الطلب 4 ثوانٍ عند عطل Sentry).
 */
class SentryReporter
{
    /** @return string|null the Sentry event id (reference), or null when disabled/failed */
    public static function capture(Throwable $e): ?string
    {
        try {
            $dsn = self::parseDsn((string) config('services.sentry.dsn', ''));
            if ($dsn === null) {
                return null;
            }

            $eventId = str_replace('-', '', (string) Str::uuid());
            $event = self::buildEvent($e, $eventId);

            $send = static function () use ($dsn, $eventId, $event): void {
                try {
                    $body = json_encode(['event_id' => $eventId, 'sent_at' => now()->toIso8601String()])."\n"
                        .json_encode(['type' => 'event', 'content_type' => 'application/json'])."\n"
                        .json_encode($event)."\n";

                    Http::withHeaders([
                        'X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_client=aqdi-laravel/1.1, sentry_key='.$dsn['key'],
                    ])
                        ->withBody($body, 'application/x-sentry-envelope')
                        ->timeout(4)
                        ->post('https://'.$dsn['host'].'/api/'.$dsn['project'].'/envelope/');
                } catch (Throwable) {
                    // never let reporting break the app
                }
            };

            if (! app()->runningInConsole() && ! app()->runningUnitTests()) {
                app()->terminating($send);
            } else {
                $send();
            }

            return $eventId;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildEvent(Throwable $e, string $eventId): array
    {
        $frames = [];
        foreach (array_slice($e->getTrace(), 0, 25) as $t) {
            $frames[] = [
                'filename' => $t['file'] ?? '[internal]',
                'lineno' => (int) ($t['line'] ?? 0),
                'function' => ($t['class'] ?? '').($t['type'] ?? '').($t['function'] ?? ''),
            ];
        }
        $frames[] = ['filename' => $e->getFile(), 'lineno' => $e->getLine(), 'function' => 'throw'];
        $frames = array_reverse($frames); // Sentry wants oldest-first

        $event = [
            'event_id' => $eventId,
            'timestamp' => now()->toIso8601String(),
            'platform' => 'php',
            'level' => 'error',
            'logger' => 'laravel',
            'server_name' => 'backend',
            'environment' => app()->environment(),
            'release' => 'aqdi-backend',
            'tags' => ['side' => 'backend'],
            'exception' => [
                'values' => [[
                    'type' => get_class($e),
                    'value' => self::scrub($e->getMessage()),
                    'stacktrace' => ['frames' => $frames],
                ]],
            ],
        ];

        try {
            if (function_exists('request') && request()) {
                $event['request'] = [
                    // بلا query string (قد يحمل جوالاً/رقم طلب/توكن) ومنقّى.
                    'url' => self::scrub(request()->url()),
                    'method' => request()->method(),
                ];
            }
        } catch (Throwable) {
            // no request context (console, etc.)
        }

        return $event;
    }

    /** تنقية النص من البيانات الشخصية والأسرار قبل إرساله خارجياً. */
    public static function scrub(string $text): string
    {
        $text = preg_replace('/\b\d+\|[A-Za-z0-9]{20,}\b/', '[redacted-token]', $text) ?? $text;
        $text = preg_replace('/Bearer\s+[A-Za-z0-9\.\-_|]+/i', 'Bearer [redacted]', $text) ?? $text;
        $text = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[redacted-email]', $text) ?? $text;
        // أرقام طويلة (جوال/هوية/سجل تجاري/IBAN): 9 أرقام فأكثر.
        $text = preg_replace('/(?<![A-Za-z0-9])\+?\d[\d\s\-]{7,}\d(?![A-Za-z0-9])/', '[redacted-number]', $text) ?? $text;
        $text = preg_replace('/\bSA\d{2}[A-Z0-9]{18,}\b/i', '[redacted-iban]', $text) ?? $text;

        return $text;
    }

    /** @return array{key: string, host: string, project: string}|null */
    private static function parseDsn(string $dsn): ?array
    {
        $dsn = trim($dsn);
        if ($dsn === '') {
            return null;
        }

        $parts = parse_url($dsn);
        $key = (string) ($parts['user'] ?? '');
        $host = (string) ($parts['host'] ?? '');
        $project = trim((string) ($parts['path'] ?? ''), '/');

        if ($key === '' || $host === '' || $project === '') {
            return null;
        }

        return ['key' => $key, 'host' => $host, 'project' => $project];
    }
}
