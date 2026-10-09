<?php

namespace App\Services\Orders;

use App\Models\Contract;
use App\Models\Employee;
use App\Support\SchemaCache;

/**
 * علامات «أدخلتها في إيجار» لكل قسم في صفحة الطلب (دفعة هـ — E1/2.6).
 * تُحفظ في contracts.ejar_entry_progress كـ JSON: {section: {done, by, by_name, at}}.
 */
final class EjarEntryProgress
{
    public const SECTIONS = ['lessor', 'property', 'unit', 'tenant', 'financial', 'conditions'];

    /**
     * @return array<string, array{done: bool, by: int|null, by_name: string|null, at: string|null}>
     */
    public static function for(Contract $contract): array
    {
        $raw = SchemaCache::hasColumn('contracts', 'ejar_entry_progress') ? $contract->getAttribute('ejar_entry_progress') : null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $raw = is_array($raw) ? $raw : [];

        $out = [];
        foreach (self::SECTIONS as $section) {
            $row = is_array($raw[$section] ?? null) ? $raw[$section] : [];
            $out[$section] = [
                'done' => (bool) ($row['done'] ?? false),
                'by' => isset($row['by']) ? (int) $row['by'] : null,
                'by_name' => $row['by_name'] ?? null,
                'at' => $row['at'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array{done: bool, by: int|null, by_name: string|null, at: string|null}>
     */
    public static function set(Contract $contract, string $section, bool $done, ?Employee $employee): array
    {
        $progress = self::for($contract);
        $progress[$section] = [
            'done' => $done,
            'by' => $done ? $employee?->id : null,
            'by_name' => $done ? $employee?->name : null,
            'at' => $done ? now()->toIso8601String() : null,
        ];
        if (SchemaCache::hasColumn('contracts', 'ejar_entry_progress')) {
            $contract->forceFill(['ejar_entry_progress' => json_encode($progress, JSON_UNESCAPED_UNICODE)])->saveQuietly();
        }

        return $progress;
    }

    /** هل كل الأقسام مُدخلة؟ */
    public static function allDone(Contract $contract): bool
    {
        foreach (self::for($contract) as $row) {
            if (! $row['done']) {
                return false;
            }
        }

        return true;
    }
}
