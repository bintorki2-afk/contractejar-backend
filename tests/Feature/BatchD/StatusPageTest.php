<?php

namespace Tests\Feature\BatchD;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * دفعة (د) — ب20: GET /api/v2/status (عامة، مختصرة، فحص البوابة مخزّن 5 دقائق).
 */
class StatusPageTest extends BatchDTestCase
{
    public function test_status_components_and_gateway_cache(): void
    {
        config(['services.moyasar.driver' => 'moyasar']);
        Cache::forget('status.gateway_reachable');
        Cache::put('scheduler.last_run', now()->toIso8601String());
        Http::fake(['https://api.moyasar.com/v1/*' => Http::response(['message' => 'unauthorized'], 401)]);

        $data = $this->getJson('/api/v2/status')->assertOk()->json();
        $this->assertSame('ok', $data['status']);
        $this->assertSame(['api', 'db', 'scheduler', 'payments'], array_column($data['components'], 'key'));
        $this->assertSame('ok', collect($data['components'])->firstWhere('key', 'payments')['status']);
        $this->assertArrayNotHasKey('tables', $data);

        // مخزّن: لا طلب ثانٍ للبوابة.
        $this->getJson('/api/v2/status')->assertOk();
        Http::assertSentCount(1);

        Cache::forget('status.gateway_reachable');
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('down')]);
        $data = $this->getJson('/api/v2/status')->assertOk()->json();
        $this->assertSame('degraded', $data['status']);
        $this->assertSame('down', collect($data['components'])->firstWhere('key', 'payments')['status']);
    }
}
