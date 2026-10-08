<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * يعمل عبر `php artisan schedule:work` (يُشغَّل في railway-start.sh بجانب الخادم).
     * المنطقة الزمنية للتطبيق Asia/Riyadh.
     */
    protected function schedule(Schedule $schedule): void
    {
        // نبضة حياة للمجدول — تقرأها نقطة /api/v2/health (scheduler_last_run).
        $schedule->call(function () {
            Cache::put('scheduler.last_run', now()->toIso8601String(), now()->addDays(2));
        })->everyMinute()->name('scheduler-heartbeat');

        // ف8: الإشعارات الذكية (طلبات غير مكتملة، بانتظار الدفع، قرب انتهاء العقد).
        $schedule->command('notifications:dispatch')
            ->everyFifteenMinutes()
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/notifications-dispatch.log'));

        // دفعة (د) — ب11: علامات تأخير الطلبات + إشعار الموظفين بالتأخير الجديد.
        $schedule->command('orders:flag-delays')
            ->everyFifteenMinutes()
            ->withoutOverlapping(30)
            ->appendOutputTo(storage_path('logs/orders-flag-delays.log'));

        // دفعة (د) — ب12: تفريغ السلة (أقدم من 30 يوماً) يومياً 04:00.
        $schedule->command('trash:purge')->dailyAt('04:00')->timezone('Asia/Riyadh')->withoutOverlapping(60);

        // ف21: نسخة احتياطية يومية لقاعدة البيانات 03:10 بتوقيت الرياض.
        $schedule->command('aqdi:db-backup')
            ->dailyAt('03:10')
            ->timezone('Asia/Riyadh')
            ->withoutOverlapping(120)
            ->appendOutputTo(storage_path('logs/db-backup.log'));

        $schedule->command('ads:sync-spend --days=3')->dailyAt('06:00');

        // دفعة (د) — ب18: تقرير المالك الأسبوعي (الأحد 09:00 الرياض) عبر تيليجرام.
        $schedule->command('reports:weekly-owner')->weeklyOn(0, '09:00')->timezone('Asia/Riyadh')->withoutOverlapping(30);
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
