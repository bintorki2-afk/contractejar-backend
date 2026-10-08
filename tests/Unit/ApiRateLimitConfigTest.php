<?php

namespace Tests\Unit;

use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** فحص (CROSS-10): الحد العام 180/د للمسجّل (لكل حساب) و120/د لغير المسجّل (لكل IP). */
class ApiRateLimitConfigTest extends TestCase
{
    public function test_api_limiter_numbers(): void
    {
        $limiter = RateLimiter::limiter('api');

        $guest = Request::create('/api/v2/pricing', 'GET', server: ['REMOTE_ADDR' => '203.0.113.1']);
        $this->assertSame(120, $limiter($guest)->maxAttempts);

        $authed = Request::create('/api/v2/contracts', 'GET', server: ['REMOTE_ADDR' => '203.0.113.1']);
        $user = new User();
        $user->id = 42;
        $authed->setUserResolver(fn () => $user);
        $limit = $limiter($authed);
        $this->assertSame(180, $limit->maxAttempts);
        $this->assertStringContainsString('42', (string) $limit->key);
    }
}
