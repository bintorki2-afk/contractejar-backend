<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * فحص (WEBSITE-1): لا يمكن تزوير IP العميل عبر X-Forwarded-For لتجاوز حدود الطلبات،
 * و IP الزائر من خادم الموقع يُقبل فقط مع السر المشترك.
 */
class TrustedClientIpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/v2/_ip_probe', fn (\Illuminate\Http\Request $r) => response()->json(['ip' => $r->ip()]));
        config(['app.trusted_proxies' => '', 'app.trusted_forwarder_secret' => 'website-shared-secret']);
    }

    public function test_direct_public_client_cannot_spoof_forwarded_for(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->getJson('/api/v2/_ip_probe', ['X-Forwarded-For' => '10.20.31.7'])
            ->assertJsonPath('ip', '203.0.113.9');
    }

    public function test_platform_proxy_hop_is_trusted_and_rightmost_client_used(): void
    {
        // وكيل Railway داخلي (خاص) أضاف IP العميل الحقيقي في آخر السلسلة.
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->getJson('/api/v2/_ip_probe', ['X-Forwarded-For' => '1.1.1.1, 198.51.100.20'])
            ->assertJsonPath('ip', '198.51.100.20');
    }

    public function test_website_forwarded_visitor_ip_requires_shared_secret(): void
    {
        // بدون السر: يبقى IP خادم الموقع (لا تزوير).
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->getJson('/api/v2/_ip_probe', ['X-Forwarded-For' => '192.0.2.77'])
            ->assertJsonPath('ip', '203.0.113.50');

        // سر خاطئ: يُتجاهل.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->getJson('/api/v2/_ip_probe', ['X-Forwarded-For' => '192.0.2.77', 'X-Forwarded-Client-Secret' => 'nope'])
            ->assertJsonPath('ip', '203.0.113.50');

        // السر الصحيح: IP الزائر يصبح عنوان العميل لحدود الطلبات.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->getJson('/api/v2/_ip_probe', ['X-Forwarded-For' => '192.0.2.77', 'X-Forwarded-Client-Secret' => 'website-shared-secret'])
            ->assertJsonPath('ip', '192.0.2.77');
    }

    public function test_rate_limit_keys_on_real_client_not_spoofed_header(): void
    {
        Route::get('/api/v2/_limited', fn () => 'ok')->middleware('throttle:3,1');

        $statuses = [];
        for ($i = 0; $i < 5; $i++) {
            $statuses[] = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
                ->get('/api/v2/_limited', ['X-Forwarded-For' => '10.0.0.'.$i])->status();
        }

        $this->assertSame([200, 200, 200, 429, 429], $statuses);
    }
}
