<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إشعارات اللوحة للموظفين (دفعة هـ): employee_id = null ⇒ لكل الموظفين.
 */
class EmployeeNotification extends Model
{
    protected $fillable = ['employee_id', 'contract_id', 'kind', 'title', 'body', 'url', 'data', 'read_at'];

    protected $casts = ['data' => 'array', 'read_at' => 'datetime'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /** @return array<string, mixed> */
    public function toArrayForAdmin(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'contract_id' => $this->contract_id,
            'order_number' => $this->contract?->uuid !== null ? (string) $this->contract->uuid : null,
            'data' => $this->data,
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
