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
        // دفعة (هـ) — E2/E5
        'kind',
        'contract_id',
        'charge_id',
        'employee_id',
        'receipt_path',
        'reference',
        'note',
    ];

    /** أنواع الدفعات (دفعة هـ). */
    public const KIND_ORIGINAL = 'original';

    public const KIND_PRICE_DIFFERENCE = 'price_difference';

    public const KIND_EXTRA_FEE = 'extra_fee';

    public const KIND_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    /** بادئة مفتاح فاتورة Moyasar لدفعات الرسوم: chg-{uuid}-{charge_id}. */
    public const CHARGE_KEY_PREFIX = 'chg-';

    public static function chargeKey(string $contractUuid, int $chargeId): string
    {
        return self::CHARGE_KEY_PREFIX.$contractUuid.'-'.$chargeId;
    }

    public static function isChargeKey(string $key): bool
    {
        return str_starts_with($key, self::CHARGE_KEY_PREFIX);
    }

    /** @return array{uuid: string, charge_id: int}|null */
    public static function parseChargeKey(string $key): ?array
    {
        if (preg_match('/^chg-(.+)-(\d+)$/', $key, $m) !== 1) {
            return null;
        }

        return ['uuid' => $m[1], 'charge_id' => (int) $m[2]];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function charge()
    {
        return $this->belongsTo(ContractCharge::class, 'charge_id');
    }

    public function isBankTransfer(): bool
    {
        return $this->payment_method === self::METHOD_BANK_TRANSFER;
    }

    /**
     * كل دفعات الطلب: الأصلية (uuid أو uuid-لاحقة) + دفعات الرسوم (chg-uuid-N) + ما يحمل contract_id.
     */
    public function scopeForContract(Builder $query, Contract $contract): Builder
    {
        $uuid = (string) $contract->uuid;

        return $query->where(function (Builder $q) use ($uuid, $contract) {
            $q->where('contract_uuid', $uuid)
                ->orWhere('contract_uuid', 'like', $uuid.'-%')
                ->orWhere('contract_uuid', 'like', self::CHARGE_KEY_PREFIX.$uuid.'-%');
            if (\App\Support\SchemaCache::hasColumn('payments', 'contract_id')) {
                $q->orWhere('contract_id', $contract->id);
            }
        });
    }

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
 