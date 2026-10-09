<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * إرسال رسالة للمالك عبر بوت تيليجرام (دفعة د — ب18/ب19). لا يرمي استثناءً، ولا يكتب التوكن في السجلات.
 */
class TelegramService
{
    public function configured(): bool
    {
        return filled(config('services.telegram.bot_token')) && filled(config('services.telegram.chat_id'));
    }

    public function send(string $text): bool
    {
        if (! $this->configured()) {
            Log::info('Telegram not configured; message skipped.');

            return false;
        }

        try {
            $response = Http::timeout(10)->asJson()->post(
                'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/sendMessage',
                [
                    'chat_id' => (string) config('services.telegram.chat_id'),
                    'text' => mb_substr($text, 0, 4000),
                    'disable_web_page_preview' => true,
                ]
            );

            if (! $response->successful()) {
                Log::warning('Telegram send failed', ['status' => $response->status()]);
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('Telegram send failed', ['error' => class_basename($e)]);

            return false;
        }
    }
}
