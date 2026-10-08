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
