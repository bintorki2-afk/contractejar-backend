<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجل نشاط الطلب (دفعة د — ب9).
 */
class ContractActivity extends Model
{
    protected $fillable = [
        'contract_id',
        'lessor_change_request_id',
        'actor_type',
        'actor_id',
        'actor_name',
        'action',
        'before',
        'after',
        'note',
        'customer_visible',
        'customer_label',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'customer_visible' => 'boolean',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'actor_id');
    }
}
