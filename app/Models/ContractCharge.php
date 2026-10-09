<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رسوم على طلب بعد الدفع (دفعة هـ — E5): فرق سعر تلقائي أو رسوم إضافية يضيفها الموظف.
 */
class ContractCharge extends Model
{
    public const KIND_PRICE_DIFFERENCE = 'price_difference';

    public const KIND_EXTRA_FEE = 'extra_fee';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const KIND_LABELS = [
        self::KIND_PRICE_DIFFERENCE => 'فرق سعر',
        self::KIND_EXTRA_FEE => 'رسوم إضافية',
    ];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'بانتظار الدفع',
        self::STATUS_PAID => 'مدفوعة',
        self::STATUS_CANCELLED => 'ملغاة',
    ];

    protected $fillable = [
        'contract_id', 'kind', 'amount', 'message', 'internal_reason', 'status', 'created_by',
        'payment_id', 'moyasar_payment_id', 'payment_key', 'paid_at', 'cancelled_at', 'cancelled_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function paymentKey(): string
    {
        return $this->payment_key ?: Payment::chargeKey((string) $this->contract?->uuid, (int) $this->id);
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
