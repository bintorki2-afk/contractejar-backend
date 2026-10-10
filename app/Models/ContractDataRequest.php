<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * طلب مرفق ناقص / تصحيح بيانات من الموظف للعميل (دفعة هـ — E4).
 */
class ContractDataRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CANCELLED = 'cancelled';

    public const SECTIONS = ['lessor', 'property', 'tenant'];

    protected $fillable = [
        'contract_id', 'section', 'items', 'note', 'status', 'requested_by', 'requested_at', 'reminded_at',
        'owner_alerted_at', 'resolved_at', 'resolved_by', 'resolved_fields',
    ];

    protected $casts = [
        'items' => 'array',
        'resolved_fields' => 'array',
        'requested_at' => 'datetime',
        'reminded_at' => 'datetime',
        'owner_alerted_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** @return list<string> */
    public function itemLabels(): array
    {
        return array_values(array_filter(array_map(static fn ($i) => (string) ($i['label'] ?? ''), $this->items ?? [])));
    }

    /** @return list<string> */
    public function itemKeys(): array
    {
        return array_values(array_filter(array_map(static fn ($i) => (string) ($i['key'] ?? ''), $this->items ?? [])));
    }

    /** الحقول (أعمدة/مفاتيح مرفقات) التي يحلّها أي بند من البنود. @return list<string> */
    public function resolvingFields(): array
    {
        $fields = [];
        foreach ($this->items ?? [] as $item) {
            foreach ((array) ($item['fields'] ?? []) as $f) {
                $fields[] = (string) $f;
            }
        }

        return array_values(array_unique($fields));
    }

    /** أصغر خطوة في المعالج تحلّ البنود (للرابط العميق). */
    public function step(): int
    {
        $steps = array_values(array_filter(array_map(static fn ($i) => (int) ($i['step'] ?? 0), $this->items ?? [])));

        return $steps === [] ? 1 : min($steps);
    }

    /** @return list<int> */
    public function steps(): array
    {
        return array_values(array_unique(array_filter(array_map(static fn ($i) => (int) ($i['step'] ?? 0), $this->items ?? []))));
    }
}
