<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'payment_date',
        'contract_uuid',
        'payment_method',
        'payment_brand',
        'tran_currency',
        'name',
        'amount',
        'status',
        // دفعة (د) — ب8
        'gateway_payment_id',
        'refunded_amount',
        'refund_status',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class, 'contract_uuid', 'uuid');
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('payments.status', 'success');
    }

    /**
     * Match payments.contract_uuid to a contract uuid (exact or with "-{suffix}").
     */
    public function scopeMatchingContractUuid(Builder $query, string|int $contractUuid): Builder
    {
        $uuid = (string) $contractUuid;

        return $query->where(function (Builder $q) use ($uuid) {
            $q->where('contract_uuid', $uuid)
                ->orWhere('contract_uuid', 'like', $uuid.'-%');
        });
    }

    /**
     * Match payments.contract_uuid to contracts.uuid on the parent query (for subqueries).
     */
    public function scopeMatchingContractUuidColumn(Builder $query, string $column = 'contracts.uuid'): Builder
    {
        return $query->where(function (Builder $q) use ($column) {
            $q->whereColumn('payments.contract_uuid', $column)
                ->orWhereRaw("payments.contract_uuid LIKE CONCAT(CAST({$column} AS CHAR), '-%')");
        });
    }

    public function scopeSuccessfulMatchingContractUuid(Builder $query, string|int $contractUuid): Builder
    {
        return $query->successful()->matchingContractUuid($contractUuid);
    }

    public function scopeSuccessfulMatchingContractUuidColumn(Builder $query, string $column = 'contracts.uuid'): Builder
    {
        return $query->successful()->matchingContractUuidColumn($column);
    }
}
 