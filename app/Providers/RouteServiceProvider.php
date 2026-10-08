<?php

namespace App\Providers;

use App\Shared\Routing\ModuleRouteLoader;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('otp-send', function (Request $request) {
            $mobile = (string) $request->input('mobile', '');

            // حدّان معاً: لكل (IP+رقم) ولكل IP إجمالاً — حتى لا يُستنزف رصيد الرسائل
            // المدفوعة بتدوير الأرقام من عنوان واحد. (نقطة 14 / CROSS-9)
            return [
                Limit::perMinutes(
                    max(1, (int) config('otp.send_http_decay_minutes', 10)),
                    max(1, (int) config('otp.send_http_max', 5))
                )->by($request->ip().'|otp-send|'.$mobile),
                Limit::perHour(
                    max(1, (int) config('otp.send_http_ip_max_per_hour', 15))
                )->by($request->ip().'|otp-send-ip'),
            ];
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            $mobile = (string) $request->input('mobile', '');

            return Limit::perMinutes(
                max(1, (int) config('otp.verify_http_decay_minutes', 1)),
                max(1, (int) config('otp.verify_http_max', 10))
            )->by($request->ip().'|otp-verify|'.$mobile);
        });

        RateLimiter::for('login', function (Request $request) {
            $identifier = strtolower((string) (
                $request->input('mobile')
                ?: $request->input('email')
                ?: ''
            ));

            return Limit::perMinute(10)->by($request->ip().'|login|'.$identifier);
        });

        RateLimiter::for('payment-public', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('api')
                ->prefix('api/v2')
                ->group(base_path('routes/api_v2.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            Route::middleware(['api', 'employee.bearer'])
                ->prefix('api/admin')
                ->group(base_path('routes/admin.php'));

            $this->app->make(ModuleRouteLoader::class)->load();
        });
    }
}
