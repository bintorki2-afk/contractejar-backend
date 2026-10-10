<?php

namespace Tests\Feature\BlogMigration;

use App\Services\Seo\UrlMigrationVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * البند 4/5/6 — فحص ما بعد النقل (قراءة فقط).
 */
class VerifySeoUrlsTest extends TestCase
{
    private function slugUrl(string $slug): string
    {
        return 'https://aqdi.sa/blog/'.rawurlencode($slug);
    }

    public function test_classifies_every_outcome(): void
    {
        $good = $this->slugUrl('تعرف-على-طريقة-التسجيل-في-منصة-إيجار');
        Http::fake([
            'aqdi.sa/ok' => Http::response('<html><head><link rel="canonical" href="https://aqdi.sa/ok"></head></html>', 200, ['Content-Type' => 'text/html']),
            'aqdi.sa/moved' => Http::response('', 301, ['Location' => $good]),
            $good => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
            'aqdi.sa/temp' => Http::response('', 302, ['Location' => $good]),
            'aqdi.sa/gone' => Http::response('', 404),
            'aqdi.sa/raw' => Http::response('', 301, ['Location' => 'https://aqdi.sa/blog/تعرف']),
            'aqdi.sa/chain' => Http::response('', 301, ['Location' => 'https://aqdi.sa/hop']),
            'aqdi.sa/hop' => Http::response('', 301, ['Location' => 'https://aqdi.sa/ok']),
            'aqdi.sa/away' => Http::response('', 301, ['Location' => 'https://evil.example/x']),
            'aqdi.sa/canon' => Http::response('<link rel="canonical" href="https://aqdi.sa/other">', 200, ['Content-Type' => 'text/html']),
            'aqdi.sa/broken' => Http::response('', 500),
        ]);

        $v = new UrlMigrationVerifier();
        $r = fn (string $path) => $v->check('https://aqdi.sa/'.$path)['result'];

        $this->assertSame('ok_200', $r('ok'));
        $this->assertSame('ok_301', $r('moved'));
        $this->assertSame('fail_temporary_redirect', $r('temp'));
        $this->assertSame('fail_not_found', $r('gone'));
        $this->assertSame('fail_non_ascii_location', $r('raw'));
        $this->assertSame('fail_redirect_chain', $r('chain'));
        $this->assertSame('fail_redirect_to_foreign_host', $r('away'));
        $this->assertSame('fail_status_500', $r('broken'));
        $this->assertSame('canonical_points_elsewhere', $v->check('https://aqdi.sa/canon')['note']);
        $this->assertSame('', $v->check('https://aqdi.sa/ok')['note']);
    }

    public function test_ip_mode_keeps_the_original_https_url(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $url = $this->slugUrl('مقال');

        $result = (new UrlMigrationVerifier('203.0.113.10'))->check($url);

        $this->assertSame('ok_200', $result['result']);
        Http::assertSent(fn (Request $req) => $req->url() === $url);
    }

    public function test_catches_redirects_that_would_lose_the_article(): void
    {
        $right = $this->slugUrl('المقال-الصحيح');
        Http::fake([
            'aqdi.sa/blog/a' => Http::response('', 301, ['Location' => 'https://aqdi.sa/']),
            'aqdi.sa/blog/b' => Http::response('', 301, ['Location' => 'https://aqdi.sa/blog']),
            'aqdi.sa/blog/c' => Http::response('', 301, ['Location' => $this->slugUrl('مقال-ثاني')]),
            'aqdi.sa/blog/d' => Http::response('', 301, ['Location' => '//evil.example/x']),
            'aqdi.sa/blog/e' => Http::response('', 301, ['Location' => 'sibling']),
            'aqdi.sa/blog/sibling' => Http::response('ok', 200),
            '*' => Http::response('ok', 200),
        ]);

        $v = new UrlMigrationVerifier(null, ['aqdi.sa'], 20, ['https://aqdi.sa/blog/c' => $right]);

        $this->assertSame('fail_redirect_to_home_or_listing', $v->check('https://aqdi.sa/blog/a')['result']);
        $this->assertSame('fail_redirect_to_home_or_listing', $v->check('https://aqdi.sa/blog/b')['result']);
        $this->assertSame('fail_wrong_target', $v->check('https://aqdi.sa/blog/c')['result']);
        $this->assertSame('fail_redirect_to_foreign_host', $v->check('https://aqdi.sa/blog/d')['result']);
        $this->assertSame('ok_301', $v->check('https://aqdi.sa/blog/e')['result'], 'relative Location resolves against the current folder');
    }

    public function test_command_fails_when_any_url_breaks_and_writes_report(): void
    {
        Http::fake([
            'aqdi.sa/a' => Http::response('ok', 200),
            'aqdi.sa/b' => Http::response('', 404),
        ]);
        $csv = tempnam(sys_get_temp_dir(), 'urls').'.csv';
        file_put_contents($csv, "url,url_decoded,clicks\nhttps://aqdi.sa/a,a,100\nhttps://aqdi.sa/b,b,40\n");
        $out = sys_get_temp_dir().'/verify-'.uniqid().'.csv';

        $code = Artisan::call('seo:verify-urls', ['--file' => $csv, '--out' => $out, '--delay' => 0]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('40', Artisan::output());
        $report = file_get_contents($out);
        $this->assertStringContainsString('fail_not_found', $report);
        $this->assertStringContainsString('ok_200', $report);
    }

    public function test_command_passes_when_all_urls_are_fine(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $csv = tempnam(sys_get_temp_dir(), 'urls').'.csv';
        file_put_contents($csv, "url,clicks\nhttps://aqdi.sa/a,1\n");

        $this->assertSame(0, Artisan::call('seo:verify-urls', ['--file' => $csv, '--out' => sys_get_temp_dir().'/v-'.uniqid().'.csv', '--delay' => 0]));
    }

    public function test_default_file_is_the_committed_baseline(): void
    {
        $this->assertFileExists(base_path('docs/seo-baseline/critical-urls-with-clicks.csv'));
    }
}
