<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * عملية استرجاع عبر Moyasar (دفعة د — ب8).
 */
class Refund extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'payment_id', 'contract_id', 'contract_uuid', 'amount', 'currency', 'gateway_payment_id',
        'moyasar_refund_id', 'status', 'employee_id', 'reason', 'failure_message', 'gateway_response',
    ];

    protected $casts = [
        'amount' => 'float',
        'gateway_response' => 'array',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return array<string, mixed> */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'payment_id' => $this->payment_id,
            'contract_id' => $this->contract_id,
            'order_number' => $this->contract_uuid,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'status_label' => match ($this->status) {
                self::STATUS_SUCCEEDED => 'تم الاسترجاع',
                self::STATUS_FAILED => 'فشل',
                default => 'قيد التنفيذ',
            },
            'moyasar_refund_id' => $this->moyasar_refund_id,
            'reason' => $this->reason,
            'failure_message' => $this->failure_message,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee?->name,
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
