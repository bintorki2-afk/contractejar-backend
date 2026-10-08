<?php

namespace Tests\Feature;

use App\Modules\Auth\Services\UserOtpService;
use App\Support\DeedImage;
use App\Support\RealEstateImage;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * دفعة الإصلاحات (ب) — B8: إصلاحات أمنية سريعة.
 */
class FixBatchBSecurityTest extends TestCase
{
    public function test_fixed_otp_code_is_ignored_in_production(): void
    {
        config(['otp.fixed_code' => '1111', 'otp.length' => 4]);

        app()->detectEnvironment(fn () => 'local');
        $this->assertSame('1111', UserOtpService::fixedCode());
        $this->assertSame('1111', app(UserOtpService::class)->generatePlain());

        app()->detectEnvironment(fn () => 'production');
        $this->assertNull(UserOtpService::fixedCode());
        $generated = app(UserOtpService::class)->generatePlain();
        $this->assertMatchesRegularExpression('/^\d{4}$/', $generated);
        // عشوائي: احتمال مطابقة الكود الثابت 1/9000 — نتحقق عبر عدة توليدات.
        $codes = array_map(fn () => app(UserOtpService::class)->generatePlain(), range(1, 5));
        $this->assertNotSame(['1111', '1111', '1111', '1111', '1111'], $codes);

        app()->detectEnvironment(fn () => 'testing');
        config(['otp.fixed_code' => '']);
        $this->assertNull(UserOtpService::fixedCode());
    }

    public function test_track_endpoint_is_throttled_to_ten_per_minute(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'v2.contract.track');

        $this->assertNotNull($route);
        $this->assertContains('throttle:10,1', $route->middleware());
    }

    public function test_signed_image_links_expire_within_thirty_minutes(): void
    {
        $this->assertLessThanOrEqual(30, DeedImage::TTL_MINUTES);
        $this->assertLessThanOrEqual(30, RealEstateImage::TTL_MINUTES);

        $source = file_get_contents(app_path('Models/LessorChangeRequest.php'));
        $this->assertMatchesRegularExpression('/addMinutes\((30|[12]?\d)\)/', $source);
    }

    public function test_api_errors_are_generic_when_debug_is_off(): void
    {
        Route::get('/api/v2/_batch_b_boom', function () {
            throw new \RuntimeException('database password is hunter2');
        });

        config(['app.debug' => false]);

        $response = $this->getJson('/api/v2/_batch_b_boom');

        $response->assertStatus(500)->assertJsonPath('success', false);
        $this->assertStringNotContainsString('hunter2', $response->getContent());
        $this->assertStringNotContainsString('RuntimeException', $response->getContent());
        $this->assertArrayNotHasKey('trace', $response->json());
    }
}
