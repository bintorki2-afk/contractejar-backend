<?php

namespace App\Services\Orders;

use App\Enums\ReceivedContractStatus;
use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\Employee;
use App\Models\ReceivedContract;
use App\Services\ContractStatusHistoryService;
use App\Services\FirebaseNotificationService;
use App\Support\ContractFrontendStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * مسار الطلب (دفعة د — ب2):
 *   new → paid → under_review → received_by_employee → whatsapp_draft → ejar_authenticated → completed
 *   حالات جانبية: cancelled, on_hold, refunded.
 *
 * - بعد نجاح الدفع ينتقل الطلب تلقائياً إلى «قيد المراجعة» (إذا كان جديداً/مدفوعاً).
 * - عند استلام الموظف ينتقل إلى «مستلم من الموظف».
 */
class OrderFlowService
{
    /** الحالات التي يُسمح فيها باستلام الطلب. */
    public const RECEIVABLE_KEYS = [ContractStatus::KEY_NEW, ContractStatus::KEY_PAID, ContractStatus::KEY_UNDER_REVIEW];

    public function __construct(
        private readonly ContractStatusHistoryService $history,
    ) {}

    /** مفتاح الحالة الحالية للطلب (new/paid/under_review/…). */
    public function currentKey(Contract $contract): ?string
    {
        if (! $contract->contract_status_id) {
            return null;
        }

        return ContractStatus::keyForId((int) $contract->contract_status_id)
            ?? ContractFrontendStatus::keyForStatusRow($contract->contractStatus()->first());
    }

    public function isReceivable(Contract $contract): bool
    {
        $key = $this->currentKey($contract);

        return $key === null || in_array($key, self::RECEIVABLE_KEYS, true);
    }

    /**
     * بعد نجاح الدفع: new/paid → under_review (مع سجل الحالة). لا يلمس الطلبات التي تقدّمت.
     */
    public function afterPayment(Contract $contract): void
    {
        $contract->refresh();
        $key = $this->currentKey($contract);
        if ($key !== null && ! in_array($key, [ContractStatus::KEY_NEW, ContractStatus::KEY_PAID], true)) {
            return;
        }

        $underReview = ContractStatus::idFor(ContractStatus::KEY_UNDER_REVIEW);
        if ($underReview === null || (bool) $contract->is_draft) {
            return;
        }

        $before = $contract->contract_status_id;
        $contract->forceFill(['contract_status_id' => $underReview])->save();
        $contract->load('contractStatus');

        try {
            $this->history->record($contract, ['source' => 'payment']);
        } catch (\Throwable $e) {
            Log::warning('afterPayment history failed', ['contract_id' => $contract->id, 'error' => $e->getMessage()]);
        }

        $this->activity($contract, 'status_changed', null, ['contract_status_id' => $before], ['contract_status_id' => $underReview], 'system');
    }

    /**
     * استلام الطلب من موظف: صف received_contracts (مرة واحدة) + الحالة «مستلم من الموظف» + سجل + إشعار.
     *
     * @return array{ok: bool, received?: ReceivedContract, code?: int, message?: string, existing?: ReceivedContract}
     */
    public function receive(Contract $contract, Employee $employee, array $attributes = [], string $source = 'receive'): array
    {
        $existing = ReceivedContract::query()->where('contract_id', $contract->id)->with('employee')->first();
        if ($existing) {
            return ['ok' => false, 'code' => 409, 'existing' => $existing, 'message' => trans('api.contract_already_received')];
        }

        if (! $this->isReceivable($contract)) {
            return [
                'ok' => false,
                'code' => 422,
                'message' => 'لا يمكن استلام الطلب — الاستلام متاح فقط للطلبات الجديدة أو قيد المراجعة.',
            ];
        }

        try {
            $received = ReceivedContract::query()->create(array_merge([
                'contract_id' => $contract->id,
                'employee_id' => $employee->id,
                'status' => ReceivedContractStatus::Finish,
                'date_of_received' => now()->toDateString(),
            ], $attributes));
        } catch (QueryException $e) {
            $existing = ReceivedContract::query()->where('contract_id', $contract->id)->with('employee')->first();
            if ($existing) {
                return ['ok' => false, 'code' => 409, 'existing' => $existing, 'message' => trans('api.contract_already_received')];
            }
            throw $e;
        }

        $before = $contract->contract_status_id;
        $contract->forceFill(['contract_status_id' => ContractStatus::receivedId()])->save();
        $contract->refresh();
        $contract->loadMissing(['contractStatus', 'draftContractStatus']);

        try {
            $this->history->record($contract, ['source' => $source === 'auto_assign' ? 'auto_assign' : 'receive']);
            app(FirebaseNotificationService::class)->notifyContractReceivedByEmployee($contract, $employee);
        } catch (\Throwable $e) {
            report($e);
        }

        $this->activity(
            $contract,
            $source === 'auto_assign' ? 'assigned' : 'received',
            $source === 'auto_assign' ? null : $employee,
            ['contract_status_id' => $before],
            ['contract_status_id' => $contract->contract_status_id, 'employee_id' => $employee->id, 'employee_name' => $employee->name],
            $source === 'auto_assign' ? 'system' : 'employee',
        );

        return ['ok' => true, 'received' => $received->load('employee')];
    }

    /**
     * سجل نشاط الطلب (ب9) — لا يرمي استثناءً.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function activity(Contract $contract, string $action, ?Employee $actor, ?array $before = null, ?array $after = null, string $actorType = 'employee', ?string $note = null): void
    {
        if (! class_exists(\App\Services\Orders\ContractActivityLogger::class)) {
            return;
        }

        try {
            app(ContractActivityLogger::class)->log($contract, $action, $actor, $before, $after, $actorType, $note);
        } catch (\Throwable $e) {
            Log::warning('contract activity log failed', ['contract_id' => $contract->id, 'action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
