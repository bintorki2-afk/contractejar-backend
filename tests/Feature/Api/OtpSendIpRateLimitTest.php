<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * فحص (نقطة 14 / CROSS-9): حد إجمالي لإرسال OTP لكل IP يمنع استنزاف رصيد الرسائل
 * بتدوير الأرقام من عنوان واحد.
 */
class OtpSendIpRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
            'otp.driver' => 'log',
            'otp.send_http_ip_max_per_hour' => 5,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_otp_send_is_capped_per_ip_across_different_mobiles(): void
    {
        $statuses = [];
        for ($i = 0; $i < 7; $i++) {
            // رقم مختلف لكل طلب — يتجاوز حدّ (IP+رقم) لكن يصطدم بحدّ IP الإجمالي.
            $mobile = '05100000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $statuses[] = $this->postJson('/api/v2/auth/otp/request', ['mobile' => $mobile])->status();
        }

        // بعد الحد (5/ساعة لكل IP) تظهر 429.
        $this->assertContains(429, $statuses, 'expected a 429 after the per-IP OTP cap');
        $this->assertSame(429, $statuses[6]);
    }
}
