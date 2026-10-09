<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| API v2 Routes
|--------------------------------------------------------------------------
*/

// Feature routes live in app/Modules/*/Routes.

/*
| Health check — self-diagnoses the API for external monitors (UptimeRobot,
| Sentry Crons, etc.). Public (no auth). Returns HTTP 200 when healthy and
| HTTP 503 when the database is unreachable or a critical reference table is
| empty (the class of outage that broke the document-type selector).
*/
Route::get('/health', function () {
    // Reference tables that MUST contain seeded data for the site to work.
    $critical = [
        'setting_contracts',
        'regions',
        'cities',
        'contract_periods',
        'payment_types',
        'unit_types',
        'unit_usages',
    ];

    $issues = [];
    $tables = [];
    $databaseOk = true;

    try {
        DB::select('select 1');
    } catch (\Throwable $e) {
        $databaseOk = false;
        $issues[] = 'database unreachable';
    }

    if ($databaseOk) {
        foreach ($critical as $table) {
            try {
                if (! Schema::hasTable($table)) {
                    $issues[] = "table '{$table}' is missing";
                    $tables[$table] = null;

                    continue;
                }
                $count = DB::table($table)->count();
                $tables[$table] = $count;
                if ($count === 0) {
                    $issues[] = "table '{$table}' is empty";
                }
            } catch (\Throwable $e) {
                $issues[] = "table '{$table}' check failed";
                $tables[$table] = null;
            }
        }
    }

    $healthy = $databaseOk && count($issues) === 0;

    // نبضة المجدول (schedule:work يكتبها كل دقيقة) — للمراقبة الخارجية.
    $schedulerLastRun = null;
    $schedulerStale = null;
    try {
        $schedulerLastRun = Cache::get('scheduler.last_run');
        if (is_string($schedulerLastRun) && $schedulerLastRun !== '') {
            $schedulerStale = now()->diffInMinutes(\Illuminate\Support\Carbon::parse($schedulerLastRun)) > 20;
        }
    } catch (\Throwable $e) {
        $schedulerLastRun = null;
    }

    // نقطة عامة لمراقبي التشغيل: نكشف أقل قدر من المعلومات. تفاصيل الجداول/المشاكل
    // (أسماء الجداول وأعدادها) تبقى في السجلّات فقط، ولا تُعرض للعامة.
    if (! $healthy) {
        \Illuminate\Support\Facades\Log::warning('Health check degraded', [
            'issues' => $issues,
            'tables' => $tables,
        ]);
    }

    return response()->json([
        'status' => $healthy ? 'ok' : 'degraded',
        'time' => now()->toIso8601String(),
        'checked_at' => now()->toIso8601String(),
        'db' => $databaseOk ? 'ok' : 'error',
        'database' => $databaseOk ? 'ok' : 'unreachable',
        'scheduler_last_run' => $schedulerLastRun,
        'scheduler_stale' => $schedulerStale,
        'reference_data_ok' => $databaseOk && count($issues) === 0,
    ], $healthy ? 200 : 503)->header('Cache-Control', 'no-store');
});

/*
| دفعة (د) — ب20: بيانات صفحة الحالة العامة (contractejar.com/status). عامة ومختصرة:
| الخادم، قاعدة البيانات، المجدول، وبوابة الدفع (فحص الوصول مخزّن 5 دقائق). بلا أي تفاصيل داخلية.
*/
Route::get('/status', function () {
    $components = [];

    $components[] = ['key' => 'api', 'label' => 'الخادم (API)', 'status' => 'ok'];

    $dbOk = true;
    try {
        DB::select('select 1');
    } catch (\Throwable) {
        $dbOk = false;
    }
    $components[] = ['key' => 'db', 'label' => 'قاعدة البيانات', 'status' => $dbOk ? 'ok' : 'down'];

    $scheduler = 'unknown';
    try {
        $last = Cache::get('scheduler.last_run');
        if (is_string($last) && $last !== '') {
            $scheduler = now()->diffInMinutes(\Illuminate\Support\Carbon::parse($last)) > 20 ? 'degraded' : 'ok';
        }
    } catch (\Throwable) {
        $scheduler = 'unknown';
    }
    $components[] = ['key' => 'scheduler', 'label' => 'المهام المجدولة (الإشعارات والنسخ الاحتياطي)', 'status' => $scheduler];

    $gateway = Cache::remember('status.gateway_reachable', now()->addMinutes(5), function () {
        try {
            if (app(\App\Services\MoyasarPaymentService::class)->isTestMode()) {
                return 'test_mode';
            }
            $base = rtrim((string) config('services.moyasar.base_url', 'https://api.moyasar.com'), '/');
            $response = \Illuminate\Support\Facades\Http::timeout(4)->connectTimeout(3)->get($base.'/v1/');

            // أي رد HTTP (حتى 401/404) يعني أن البوابة متاحة؛ 5xx = عطل.
            return $response->status() >= 500 ? 'degraded' : 'ok';
        } catch (\Throwable) {
            return 'down';
        }
    });
    $components[] = ['key' => 'payments', 'label' => 'بوابة الدفع (Moyasar)', 'status' => $gateway];

    $statuses = array_column($components, 'status');
    $overall = ! $dbOk ? 'down' : (count(array_intersect($statuses, ['down', 'degraded'])) > 0 ? 'degraded' : 'ok');
    $labels = ['ok' => 'كل الأنظمة تعمل', 'degraded' => 'بعض الخدمات متأثرة', 'down' => 'عطل في الخدمة'];

    return response()->json([
        'status' => $overall,
        'status_label' => $labels[$overall],
        'components' => $components,
        // متابعة دفعة (د): نفس الحالات كخريطة مختصرة (يقرؤها الموقع).
        'checks' => [
            'api' => 'ok',
            'db' => $dbOk ? 'ok' : 'down',
            'database' => $dbOk ? 'ok' : 'down',
            'scheduler' => $scheduler,
            'gateway' => $gateway,
        ],
        'checked_at' => now()->toIso8601String(),
    ], $dbOk ? 200 : 503)->header('Cache-Control', 'public, max-age=30');
})->middleware('throttle:60,1');
