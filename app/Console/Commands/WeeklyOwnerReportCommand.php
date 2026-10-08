<?php

namespace App\Console\Commands;

use App\Services\Admin\WeeklyOwnerReportService;
use App\Services\TelegramService;
use Illuminate\Console\Command;

/** دفعة (د) — ب18: تقرير المالك الأسبوعي عبر تيليجرام (الأحد 09:00 الرياض). */
class WeeklyOwnerReportCommand extends Command
{
    protected $signature = 'reports:weekly-owner {--dry-run : اطبع الرسالة دون إرسال}';

    protected $description = 'Send the weekly owner summary (orders, revenue, notarization time, slowest order, top employee, delayed) to Telegram';

    public function handle(WeeklyOwnerReportService $reports, TelegramService $telegram): int
    {
        $text = $reports->text($reports->build());

        if ($this->option('dry-run')) {
            $this->line($text);

            return self::SUCCESS;
        }

        $sent = $telegram->send($text);
        $this->info($sent ? 'sent' : 'not sent (telegram not configured or failed)');

        return self::SUCCESS;
    }
}
