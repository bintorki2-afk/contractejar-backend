<?php

namespace Tests\Unit;

use App\Support\SentryReporter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** فحص (CROSS-12): Sentry — DSN من البيئة فقط، وتنقية البيانات الشخصية قبل الإرسال. */
class SentryReporterTest extends TestCase
{
    public function test_disabled_without_dsn(): void
    {
        config(['services.sentry.dsn' => '']);
        Http::fake();

        $this->assertNull(SentryReporter::capture(new \RuntimeException('x')));
        Http::assertNothingSent();
    }

    public function test_pii_and_tokens_are_scrubbed_from_reported_event(): void
    {
        config(['services.sentry.dsn' => 'https://abc123@o1.ingest.example.io/42']);
        Http::fake();

        $id = SentryReporter::capture(new \RuntimeException(
            "SQLSTATE: insert into users (mobile, tenant_id_num, email) values (00966551234567, 1098765432, x.y@mail.com) token 14|AbCdEfGhIjKlMnOpQrStUvWxYz0123"
        ));
        $this->assertNotNull($id);

        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($request->url(), 'o1.ingest.example.io/api/42/envelope')
                && ! str_contains($body, '551234567')
                && ! str_contains($body, '1098765432')
                && ! str_contains($body, 'x.y@mail.com')
                && ! str_contains($body, 'AbCdEfGhIjKlMnOpQrStUvWxYz0123')
                && str_contains($body, '[redacted-number]');
        });
    }

    public function test_no_hardcoded_dsn_in_code(): void
    {
        $source = file_get_contents(app_path('Support/SentryReporter.php'));
        $this->assertDoesNotMatchRegularExpression('/[a-f0-9]{32}/', $source);
    }
}
