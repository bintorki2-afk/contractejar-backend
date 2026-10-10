<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\MessageTemplate;
use App\Support\MessageTemplateDefaults;
use App\Support\SchemaCache;
use App\Support\SmartLink;
use App\Support\SupportContact;

/**
 * قوالب الرسائل (دفعة د — ب16): القالب من الجدول (قابل للتعديل من اللوحة) ثم الافتراضي.
 */
class MessageTemplateService
{
    /**
     * @param  array<string, scalar|null>  $vars
     * @return array{title: string|null, body: string, key: string, channel: string, source: string}|null
     */
    public function render(string $key, string $channel, array $vars = [], ?string $fallbackBody = null, ?string $fallbackTitle = null): ?array
    {
        $template = $this->find($key, $channel);
        $source = 'database';
        if ($template === null) {
            $default = collect(MessageTemplateDefaults::ROWS)->first(fn ($r) => $r['key'] === $key && $r['channel'] === $channel);
            if ($default === null && $fallbackBody === null) {
                return null;
            }
            $source = $default ? 'default' : 'fallback';
            $title = $default['title'] ?? $fallbackTitle;
            $body = $default['body'] ?? (string) $fallbackBody;
        } else {
            $title = $template->title ?? $fallbackTitle;
            $body = (string) $template->body;
        }

        return [
            'key' => $key,
            'channel' => $channel,
            'title' => $title !== null ? $this->fill($title, $vars) : null,
            'body' => $this->fill($body, $vars),
            'source' => $source,
        ];
    }

    /**
     * متغيرات طلب جاهزة.
     *
     * @return array<string, string>
     */
    public function varsFor(Contract $contract, array $extra = []): array
    {
        $contract->loadMissing('user');
        $name = trim((string) ($contract->user?->name ?? ''));

        return array_merge([
            'order' => (string) ($contract->uuid ?: $contract->id),
            'name' => $name !== '' ? $name : 'عميلنا العزيز',
            'link' => SmartLink::for($contract),
            'amount' => '',
            'draft_number' => (string) ($contract->ejar_contract_draft_number ?? ''),
            'support' => SupportContact::whatsappLocal(),
            // دفعة (هـ): بيانات الحوالة من الإعدادات + متغيرات الرسوم/المرفقات (تُملأ عند الحاجة).
            ...$this->bankVars(),
            'items' => '',
            'reason' => '',
            'payment_url' => SmartLink::for($contract),
        ], array_map(static fn ($v) => (string) $v, $extra));
    }

    /** @return array{bank: string, iban: string, account_name: string} */
    public function bankVars(): array
    {
        try {
            $setting = \App\Models\Setting::query()->first();
        } catch (\Throwable) {
            $setting = null;
        }

        return [
            'bank' => (string) ($setting?->bank_name ?? ''),
            'iban' => (string) ($setting?->bank_iban ?? ''),
            'account_name' => (string) ($setting?->bank_account_name ?? ''),
        ];
    }

    /**
     * @param  array<string, scalar|null>  $vars
     */
    public function fill(string $text, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{'.trim((string) $k, '{}').'}'] = (string) ($v ?? '');
        }

        return strtr($text, $map);
    }

    private function find(string $key, string $channel): ?MessageTemplate
    {
        if (! SchemaCache::hasTable('message_templates')) {
            return null;
        }

        return MessageTemplate::query()->where('key', $key)->where('channel', $channel)->where('is_active', true)->first();
    }
}
