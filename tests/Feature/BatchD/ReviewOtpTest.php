<?php

namespace Tests\Feature\BatchD;

use App\Models\User;
use App\Modules\Auth\Services\UserOtpService;
use Illuminate\Support\Facades\RateLimiter;

/**
 * دفعة (د) — ب4: رقم مراجعة المتاجر 0597500013 يحصل على كود ثابت (يعمل في الإنتاج) — ولا أي رقم آخر.
 */
class ReviewOtpTest extends BatchDTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'otp.review_mobile' => '0597500013',
            'otp.review_code' => '4826',
            'otp.fixed_code' => null,
            'otp.driver' => 'log',
        ]);
        foreach (['00966597500013', '00966551112222'] as $m) {
            RateLimiter::clear('otp-send-cooldown:'.$m);
            RateLimiter::clear('otp-send-hourly:'.$m);
        }
    }

    public function test_review_number_gets_the_fixed_code_in_production_only_for_that_number(): void
    {
        $this->app['env'] = 'production';
        $this->assertTrue(app()->environment('production'));

        $service = app(UserOtpService::class);
        foreach (['0597500013', '966597500013', '00966597500013', '+966 59 750 0013'] as $variant) {
            $this->assertSame('4826', UserOtpService::reviewCodeFor($variant), $variant);
        }
        $this->assertNull(UserOtpService::reviewCodeFor('0551112222'));
        $this->assertNull(UserOtpService::reviewCodeFor('0597500014'));

        // الكود الثابت العام يبقى معطّلاً في الإنتاج.
        config(['otp.fixed_code' => '1111']);
        $this->assertNull(UserOtpService::fixedCode());
    }

    public function test_review_login_flow_end_to_end_and_other_numbers_rejected(): void
    {
        $this->app['env'] = 'production';

        $this->postJson('/api/v2/auth/otp/request', ['mobile' => '0597500013'])->assertOk();
        $user = User::query()->where('mobile', '00966597500013')->firstOrFail();
        $ok = app(UserOtpService::class)->verify($user, UserOtpService::VERIFICATION, '4826');
        $this->assertTrue($ok['ok']);

        $this->postJson('/api/v2/auth/otp/request', ['mobile' => '0551112222'])->assertOk();
        $other = User::query()->where('mobile', '00966551112222')->firstOrFail();
        $bad = app(UserOtpService::class)->verify($other, UserOtpService::VERIFICATION, '4826');
        $this->assertFalse($bad['ok']);
    }

    public function test_review_number_is_still_rate_limited(): void
    {
        $this->postJson('/api/v2/auth/otp/request', ['mobile' => '0597500013'])->assertOk();
        // إعادة الإرسال فوراً ⇒ مهلة الانتظار نفسها.
        $this->postJson('/api/v2/auth/otp/request', ['mobile' => '0597500013'])->assertStatus(429);
    }

    public function test_disabled_when_code_missing(): void
    {
        config(['otp.review_code' => '']);
        $this->assertNull(UserOtpService::reviewCodeFor('0597500013'));
    }
}
