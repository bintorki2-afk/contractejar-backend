<?php

namespace App\Services\Payments;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundableContract;
use App\Services\ContractStatusHistoryService;
use App\Services\CustomerNotificationService;
use App\Services\MoyasarPaymentService;
use App\Services\Orders\OrderFlowService;
use App\Support\SchemaCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * الاسترجاع عبر Moyasar (دفعة د — ب8): كلي أو جزئي، idempotent، مع انعكاس على الطلب والفاتورة والإشعارات.
 */
class PaymentRefundService
{
    public function __construct(
        private readonly MoyasarPaymentService $gateway,
        private readonly OrderFlowService $flow,
    ) {}

    /**
     * @throws ValidationException
     */
    public function refund(Payment $payment, ?float $amount, string $reason, ?Employee $employee): Refund
    {
        if ($payment->status !== 'success') {
            throw ValidationException::withMessages(['payment' => ['لا يمكن استرجاع دفعة غير ناجحة.']]);
        }

        /** @var Refund $refund */
        $refund = DB::transaction(function () use ($payment, $amount, $reason, $employee) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (Refund::query()->where('payment_id', $locked->id)->where('status', Refund::STATUS_PENDING)->exists()) {
                throw ValidationException::withMessages(['payment' => ['يوجد استرجاع قيد التنفيذ لهذه الدفعة.']]);
            }

            $remaining = round((float) $locked->amount - (float) ($locked->refunded_amount ?? 0), 2);
            if ($remaining <= 0) {
                throw ValidationException::withMessages(['amount' => ['تم استرجاع هذه الدفعة بالكامل مسبقاً.']]);
            }

            $value = $amount !== null ? round($amount, 2) : $remaining;
            if ($value <= 0) {
                throw ValidationException::withMessages(['amount' => ['مبلغ الاسترجاع يجب أن يكون أكبر من صفر.']]);
            }
            if ($value > $remaining + 0.009) {
                throw ValidationException::withMessages(['amount' => ['المبلغ أكبر من المتبقي القابل للاسترجاع ('.$remaining.' ر.س).']]);
            }

            $contract = Contract::query()->where('uuid', (string) $locked->contract_uuid)->first();

            return Refund::query()->create([
                'payment_id' => $locked->id,
                'contract_id' => $contract?->id,
                'contract_uuid' => (string) $locked->contract_uuid,
                'amount' => $value,
                'currency' => (string) ($locked->tran_currency ?: 'SAR'),
                'gateway_payment_id' => $locked->gateway_payment_id,
                'status' => Refund::STATUS_PENDING,
                'employee_id' => $employee?->id,
                'reason' => $reason,
            ]);
        });

        $isFullOfPayment = abs(((float) $payment->amount - (float) ($payment->refunded_amount ?? 0)) - (float) $refund->amount) < 0.01;
        $result = $this->gateway->refund($payment, $isFullOfPayment ? null : (float) $refund->amount);

        if (! $result['success']) {
            $refund->forceFill([
                'status' => Refund::STATUS_FAILED,
                'failure_message' => $result['message'],
                'gateway_payment_id' => $result['gateway_payment_id'] ?? $refund->gateway_payment_id,
                'gateway_response' => $result['data'],
            ])->save();
            if ($refund->contract) {
                $this->flow->activity($refund->contract, 'refund_failed', $employee, null, ['amount' => (float) $refund->amount], 'employee', $result['message']);
            }

            return $refund;
        }

        DB::transaction(function () use ($refund, $payment, $result) {
            $refund->forceFill([
                'status' => Refund::STATUS_SUCCEEDED,
                'moyasar_refund_id' => $result['refund_id'],
                'gateway_payment_id' => $result['gateway_payment_id'] ?? $refund->gateway_payment_id,
                'gateway_response' => $result['data'],
            ])->save();

            $refunded = round((float) ($payment->refunded_amount ?? 0) + (float) $refund->amount, 2);
            $payment->forceFill([
                'refunded_amount' => $refunded,
                'refund_status' => $refunded + 0.009 >= (float) $payment->amount ? 'full' : 'partial',
                'gateway_payment_id' => $payment->gateway_payment_id ?: ($result['gateway_payment_id'] ?? null),
            ])->save();
        });

        $this->reflectOnContract($refund->fresh(['contract']), $employee);

        return $refund->fresh();
    }

    /** إجمالي المسترجع بنجاح لطلب. */
    public static function refundedTotalFor(Contract $contract): float
    {
        if (! SchemaCache::hasTable('refunds') || ! filled($contract->uuid)) {
            return 0.0;
        }

        return round((float) Refund::query()->where('contract_uuid', (string) $contract->uuid)->where('status', Refund::STATUS_SUCCEEDED)->sum('amount'), 2);
    }

    /**
     * ملخص الاسترجاع للعميل (متابعة دفعة د): {status: none|partial|full, amount, refunded_at}.
     * المصدر: استرجاعات Moyasar الناجحة، ثم سجل الاسترجاع القديم (refundable_contracts.is_refunded).
     *
     * @return array{status: string, amount: float, refunded_at: string|null}
     */
    public static function summaryFor(Contract $contract): array
    {
        $none = ['status' => 'none', 'amount' => 0.0, 'refunded_at' => null];
        if (! $contract->exists) {
            return $none;
        }

        $amount = self::refundedTotalFor($contract);
        $at = null;
        if ($amount > 0) {
            $at = Refund::query()->where('contract_uuid', (string) $contract->uuid)->where('status', Refund::STATUS_SUCCEEDED)->max('created_at');
        } elseif (SchemaCache::hasTable('refundable_contracts')) {
            $legacy = RefundableContract::query()->where('contract_id', $contract->id)->where('is_refunded', true)->latest('id')->first();
            if ($legacy === null) {
                return $none;
            }
            $amount = round((float) $legacy->refund_amount, 2);
            $at = $legacy->updated_at;
            if ($amount <= 0) {
                return ['status' => 'full', 'amount' => 0.0, 'refunded_at' => $at ? \Illuminate\Support\Carbon::parse($at)->toIso8601String() : null];
            }
        } else {
            return $none;
        }

        $paid = self::paidTotalFor($contract);
        $full = $paid <= 0 || $amount + 0.009 >= $paid;

        return [
            'status' => $full ? 'full' : 'partial',
            'amount' => $amount,
            'refunded_at' => $at ? \Illuminate\Support\Carbon::parse($at)->toIso8601String() : null,
        ];
    }

    public static function paidTotalFor(Contract $contract): float
    {
        if (! filled($contract->uuid)) {
            return 0.0;
        }

        return round((float) Payment::query()->successfulMatchingContractUuid((string) $contract->uuid)->sum('amount'), 2);
    }

    /**
     * دفعات الطلب للّوحة (لزر «استرجاع»).
     *
     * @return list<array<string, mixed>>
     */
    public static function paymentsFor(Contract $contract): array
    {
        if (! filled($contract->uuid)) {
            return [];
        }

        return Payment::query()->matchingContractUuid((string) $contract->uuid)->orderBy('id')->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'status' => $p->status,
                'method' => $p->payment_method,
                'brand' => $p->payment_brand,
                'currency' => $p->tran_currency,
                'paid_at' => optional($p->created_at)?->toIso8601String(),
                'has_gateway_id' => filled($p->gateway_payment_id ?? null),
                'refunded_amount' => (float) ($p->refunded_amount ?? 0),
                'refund_status' => $p->refund_status ?? null,
                'refundable_amount' => $p->status === 'success' ? max(0, round((float) $p->amount - (float) ($p->refunded_amount ?? 0), 2)) : 0.0,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public static function refundsFor(Contract $contract): array
    {
        if (! SchemaCache::hasTable('refunds') || ! filled($contract->uuid)) {
            return [];
        }

        return Refund::query()->with('employee:id,name')->where('contract_uuid', (string) $contract->uuid)->orderBy('id')->get()
            ->map(fn (Refund $r) => $r->toAdminArray())->values()->all();
    }

    private function reflectOnContract(Refund $refund, ?Employee $employee): void
    {
        $contract = $refund->contract;
        if ($contract === null) {
            return;
        }

        $totalRefunded = self::refundedTotalFor($contract);
        $paid = self::paidTotalFor($contract);
        $full = $paid <= 0 || $totalRefunded + 0.009 >= $paid;

        // سجل الاسترجاع القديم (صفحة المرتجعات/التقارير/ملف العميل) يتبع المبلغ الفعلي.
        $row = RefundableContract::query()->where('contract_id', $contract->id)->latest('id')->first();
        $attrs = [
            'contract_id' => $contract->id,
            'user_id' => $contract->user_id,
            'employee_id' => $employee?->id ?? $row?->employee_id ?? Employee::query()->value('id'),
            'refund_amount' => $totalRefunded,
            'notes' => $refund->reason,
            'admin_confirmed' => true,
            'is_refunded' => $full,
            'has_draft_contract' => filled($contract->draft_before_paid) || filled($contract->draft_after_paid),
        ];
        $row ? $row->forceFill($attrs)->save() : RefundableContract::query()->forceCreate($attrs);

        $this->flow->activity($contract, 'refunded', $employee, null, [
            'amount' => (float) $refund->amount, 'total_refunded' => $totalRefunded, 'full' => $full, 'refund_id' => $refund->id,
        ], 'employee', $refund->reason);

        if ($full) {
            $refundedId = ContractStatus::refundedId();
            if ($refundedId !== null && (int) $contract->contract_status_id !== $refundedId) {
                $before = $contract->contract_status_id;
                $contract->forceFill(['contract_status_id' => $refundedId])->save();
                $contract->load('contractStatus');
                try {
                    app(ContractStatusHistoryService::class)->record($contract, ['source' => 'refund']);
                } catch (\Throwable $e) {
                    report($e);
                }
                $this->flow->activity($contract, 'status_changed', $employee, ['contract_status_id' => $before], ['contract_status_id' => $refundedId]);
            }
        }

        try {
            app(CustomerNotificationService::class)->refunded($contract, (float) $refund->amount, $full);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
