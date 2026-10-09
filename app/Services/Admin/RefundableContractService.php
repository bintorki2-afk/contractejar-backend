<?php

namespace App\Services\Admin;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\Employee;
use App\Models\RefundableContract;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RefundableContractService
{
    public const PERIODS = ['today', 'week', 'month', 'year', 'total'];

    /** حالة «مسترجع» تُحدَّد بالمفتاح refunded (لا رقم ثابت — دفعة د). */
    public static function returnStatusId(): int
    {
        return ContractStatus::refundedId() ?? -1;
    }

    public function baseQuery(): Builder
    {
        return RefundableContract::query()
            ->whereHas('contract', fn (Builder $q) => $q
                ->where('contract_status_id', self::returnStatusId())
                ->where('is_delete', 0)
            )
            ->with([
                'contract.user:id,fname,lname,mobile',
                'contract.contractStatus:id,name,color',
                'employee:id,name',
            ]);
    }

    /**
     * Resolve a refundable row for admin actions.
     *
     * Accepted {id} values (in order):
     * 1. refundable_contracts.id (refund_id / refundable_contract_id)
     * 2. contracts.uuid (order uuid / contract_uuid — preferred by frontend)
     * 3. contracts.id (contract_id)
     */
    public function findForAdmin(string|int $key): ?RefundableContract
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }

        $query = $this->adminLookupQuery();

        if (ctype_digit($key)) {
            $byRefundId = (clone $query)->whereKey((int) $key)->first();
            if ($byRefundId) {
                return $byRefundId;
            }
        }

        $byUuid = (clone $query)
            ->whereHas('contract', fn (Builder $q) => $q
                ->where('uuid', $key)
                ->where('is_delete', 0)
            )
            ->latest('refundable_contracts.id')
            ->first();

        if ($byUuid) {
            return $byUuid;
        }

        if (! ctype_digit($key)) {
            return null;
        }

        return $query
            ->where('contract_id', (int) $key)
            ->latest('refundable_contracts.id')
            ->first();
    }

    /**
     * Same eager loads as list, without forcing return-status filter so
     * lookup by contract id/uuid still works after status changes.
     */
    private function adminLookupQuery(): Builder
    {
        return RefundableContract::query()
            ->whereHas('contract', fn (Builder $q) => $q->where('is_delete', 0))
            ->with([
                'contract.user:id,fname,lname,mobile',
                'contract.contractStatus:id,name,color',
                'employee:id,name',
            ]);
    }

    /**
     * Contract eligible for a refund request (not already returned).
     */
    public function resolveReturnContract(string|int $key): Contract
    {
        $contract = $this->findContractByUuidOrId((string) $key);

        if (! $contract) {
            throw new InvalidArgumentException(trans('api.contract_not_found'));
        }

        if ((int) $contract->contract_status_id === self::returnStatusId()) {
            throw new InvalidArgumentException(trans('api.refund_contract_already_returned'));
        }

        return $contract;
    }

    /**
     * Admin frontend sends the displayed order number (contracts.uuid, 6 digits),
     * so uuid wins first, then primary key — same convention as findForAdmin().
     */
    private function findContractByUuidOrId(string $key): ?Contract
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        $base = Contract::query()->where('is_delete', 0);

        $byUuid = (clone $base)->where('uuid', $key)->first();
        if ($byUuid) {
            return $byUuid;
        }

        if (! ctype_digit($key)) {
            return null;
        }

        return (clone $base)->whereKey((int) $key)->first();
    }

    public function applyPeriod(Builder $query, string $period): Builder
    {
        if (! in_array($period, self::PERIODS, true)) {
            throw new InvalidArgumentException("Unknown period: {$period}");
        }

        return match ($period) {
            'today' => $query->whereDate('refundable_contracts.created_at', Carbon::today()),
            'week' => $query->whereBetween('refundable_contracts.created_at', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ]),
            'month' => $query->whereBetween('refundable_contracts.created_at', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ]),
            'year' => $query->whereBetween('refundable_contracts.created_at', [
                Carbon::now()->startOfYear(),
                Carbon::now()->endOfYear(),
            ]),
            'total' => $query,
        };
    }

    public function resolvePeriod(?string $period, ?string $createdAt = null): string
    {
        if ($createdAt !== null && strtolower(trim($createdAt)) === 'all') {
            return 'total';
        }

        $period = $period ?: 'today';

        if (! in_array($period, self::PERIODS, true)) {
            throw new InvalidArgumentException('period must be one of: '.implode(', ', self::PERIODS));
        }

        return $period;
    }

    /**
     * Period-scoped query for summaries (without list-only filters).
     */
    public function periodQuery(string $period): Builder
    {
        $query = $this->baseQuery();
        $this->applyPeriod($query, $period);

        return $query;
    }

    /**
     * Header KPI counts for return-orders admin UI.
     *
     * @return array{pending: int, processing: int, completed: int, rejected: int}
     */
    public function getManagementApprovalSummary(Builder $periodQuery): array
    {
        $pending = (clone $periodQuery)->whereNull('refundable_contracts.admin_confirmed')->count();
        $processing = (clone $periodQuery)
            ->where('refundable_contracts.admin_confirmed', true)
            ->where('refundable_contracts.is_refunded', false)
            ->count();
        $completed = (clone $periodQuery)
            ->where('refundable_contracts.admin_confirmed', true)
            ->where('refundable_contracts.is_refunded', true)
            ->count();
        $rejected = (clone $periodQuery)
            ->where('refundable_contracts.admin_confirmed', false)
            ->count();

        return [
            'pending' => $pending,
            'processing' => $processing,
            'completed' => $completed,
            'rejected' => $rejected,
        ];
    }

    /**
     * Counts per contract_status from `contract_statuses` for refundable rows in period.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getContractStatusSummary(Builder $periodQuery): array
    {
        $countsByStatus = (clone $periodQuery)
            ->join('contracts', 'refundable_contracts.contract_id', '=', 'contracts.id')
            ->whereNotNull('contracts.contract_status_id')
            ->select('contracts.contract_status_id', DB::raw('COUNT(*) as contracts_count'))
            ->groupBy('contracts.contract_status_id')
            ->pluck('contracts_count', 'contract_status_id');

        $statuses = ContractStatus::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'name', 'color', 'color_text']);

        if ($statuses->isEmpty()) {
            $statuses = ContractStatus::query()->orderBy('id')->get(['id', 'name', 'color', 'color_text']);
        }

        return $statuses->map(fn (ContractStatus $status) => [
            'contract_status_id' => $status->id,
            'name' => $status->name,
            'color' => $status->color,
            'color_text' => $status->color_text,
            'count' => (int) ($countsByStatus[$status->id] ?? 0),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildIndexSummary(string $period): array
    {
        $periodQuery = $this->periodQuery($period);

        return [
            'management_approval' => $this->getManagementApprovalSummary($periodQuery),
            'contract_statuses' => $this->getContractStatusSummary($periodQuery),
        ];
    }

    /**
     * Resolve contract id from draft/order number (e.g. "000042" → 42).
     */
    public function resolveContractIdFromDraftNumber(string $draftNumber): ?int
    {
        $digits = preg_replace('/\D/', '', $draftNumber) ?? '';

        if ($digits === '') {
            return null;
        }

        $id = (int) ltrim($digits, '0');

        if ($id < 1) {
            $id = (int) $digits;
        }

        return $id > 0 ? $id : null;
    }

    /**
     * Resolve contract id from store payload (orders return list or draft number).
     */
    public function resolveContractIdFromPayload(array $data): int
    {
        if (! empty($data['contract_id'])) {
            return (int) $data['contract_id'];
        }

        $contractId = $this->resolveContractIdFromDraftNumber((string) ($data['draft_contract_number'] ?? ''));

        if ($contractId === null) {
            throw new InvalidArgumentException(trans('api.invalid_draft_contract_number'));
        }

        return $contractId;
    }

    /**
     * Resolve the return-order contract from the store payload:
     * contract_id first (uuid then primary key), then draft_contract_number.
     */
    public function resolveReturnContractFromPayload(array $data): Contract
    {
        $candidates = [];

        if (! empty($data['contract_id'])) {
            $candidates[] = trim((string) $data['contract_id']);
        }

        $fromDraft = $this->resolveContractIdFromDraftNumber((string) ($data['draft_contract_number'] ?? ''));
        if ($fromDraft !== null) {
            $candidates[] = (string) $fromDraft;
        }

        if ($candidates === []) {
            throw new InvalidArgumentException(trans('api.invalid_draft_contract_number'));
        }

        foreach ($candidates as $candidate) {
            $contract = $this->findContractByUuidOrId($candidate);

            if (! $contract) {
                continue;
            }

            if ((int) $contract->contract_status_id === self::returnStatusId()) {
                throw new InvalidArgumentException(trans('api.refund_contract_already_returned'));
            }

            return $contract;
        }

        throw new InvalidArgumentException(trans('api.contract_not_found'));
    }

    /**
     * Employee refund request (طلب إسترجاع).
     * Creates the refundable row and sets contract status to مسترجع (2).
     *
     * @param  array{contract_id?: int|string, draft_contract_number?: string, refund_amount: float|int|string, notes?: string|null}  $data
     */
    public function createRefundRequest(Employee $employee, array $data): RefundableContract
    {
        $contract = $this->resolveReturnContractFromPayload($data);

        // Business rule: a refund can only exist for an order that was actually paid.
        if (! $this->contractIsPaid($contract)) {
            throw new InvalidArgumentException(trans('api.refund_requires_paid_contract'));
        }

        // Business rule: never refund more than was actually collected for the order.
        $paidTotal = $this->paidTotalFor($contract);
        if ($paidTotal > 0 && (float) ($data['refund_amount'] ?? 0) > $paidTotal + 0.009) {
            throw new InvalidArgumentException(trans('api.refund_amount_exceeds_paid', ['amount' => rtrim(rtrim(number_format($paidTotal, 2, '.', ''), '0'), '.')]));
        }

        $pendingExists = RefundableContract::query()
            ->where('contract_id', $contract->id)
            ->whereNull('admin_confirmed')
            ->where('is_refunded', false)
            ->exists();

        if ($pendingExists) {
            throw new InvalidArgumentException(trans('api.refund_request_already_exists'));
        }

        $hasDraft = filled($contract->draft_before_paid) || filled($contract->draft_after_paid);

        $record = RefundableContract::query()->create([
            'contract_id' => $contract->id,
            'user_id' => $contract->user_id,
            'employee_id' => $employee->id,
            'has_draft_contract' => $hasDraft,
            'refund_amount' => $data['refund_amount'],
            'notes' => $data['notes'] ?? null,
            'admin_confirmed' => null,
            'is_refunded' => false,
        ]);

        $contract->update([
            'contract_status_id' => self::returnStatusId(),
            'updated_at' => now(),
        ]);

        return $this->adminLookupQuery()->findOrFail($record->id);
    }

    private function paidTotalFor(\App\Models\Contract $contract): float
    {
        if (! filled($contract->uuid)) {
            return 0.0;
        }

        return (float) \App\Models\Payment::query()
            ->successfulMatchingContractUuid($contract->uuid)
            ->sum('amount');
    }

    /**
     * A contract is refundable only if it is paid: marked completed or has a successful payment.
     */
    private function contractIsPaid(\App\Models\Contract $contract): bool
    {
        if ((bool) $contract->is_completed) {
            return true;
        }

        if (! filled($contract->uuid)) {
            return false;
        }

        return \App\Models\Payment::query()
            ->successfulMatchingContractUuid($contract->uuid)
            ->exists();
    }

    /**
     * Resolve admin action from request payload.
     *
     * @param  array<string, mixed>  $data
     * @return 'approve'|'reject'|'retract'
     */
    public function resolveAdminAction(array $data): string
    {
        if (! empty($data['action'])) {
            $action = strtolower(trim((string) $data['action']));

            if (in_array($action, ['approve', 'reject', 'retract'], true)) {
                return $action;
            }
        }

        if (array_key_exists('admin_confirmed', $data) && $data['admin_confirmed'] === true) {
            return 'approve';
        }

        if (array_key_exists('admin_confirmed', $data) && $data['admin_confirmed'] === false) {
            $amount = (float) ($data['refund_amount'] ?? 0);

            return $amount > 0 ? 'retract' : 'reject';
        }

        throw new InvalidArgumentException(trans('api.refund_update_requires_field'));
    }

    /**
     * Admin: update refund data and/or management approval (موافقة الإدارة).
     *
     * @param  array{action?: string, admin_confirmed?: bool, refund_amount?: float|int|string|null, notes?: string|null}  $data
     */
    public function applyAdminUpdate(RefundableContract $record, array $data, ?Employee $employee = null): RefundableContract
    {
        $action = $this->resolveAdminAction($data);

        if ($action === 'approve') {
            if ($record->admin_confirmed === true) {
                throw new InvalidArgumentException(trans('api.refund_already_approved'));
            }
            if ($record->admin_confirmed === false) {
                throw new InvalidArgumentException(trans('api.refund_already_rejected'));
            }
        }

        if ($action === 'reject' && $record->admin_confirmed === false) {
            throw new InvalidArgumentException(trans('api.refund_already_rejected'));
        }

        if ($action === 'reject' && $record->admin_confirmed === true) {
            throw new InvalidArgumentException(trans('api.refund_already_approved'));
        }

        $updates = [];

        if (array_key_exists('refund_amount', $data) && $data['refund_amount'] !== null) {
            $amount = (float) $data['refund_amount'];
            if ($action === 'approve' && $amount <= 0) {
                throw new InvalidArgumentException(trans('api.refund_amount_invalid'));
            }
            $updates['refund_amount'] = $data['refund_amount'];
        } elseif ($action === 'approve' && (float) $record->refund_amount <= 0) {
            throw new InvalidArgumentException(trans('api.refund_amount_invalid'));
        }

        if (array_key_exists('notes', $data)) {
            $updates['notes'] = $data['notes'];
        }

        match ($action) {
            'approve' => $updates['admin_confirmed'] = true,
            'reject' => $updates['admin_confirmed'] = false,
            'retract' => $updates['admin_confirmed'] = null,
        };

        // Customer refund (is_refunded) is separate from admin approval.
        if ($action !== 'approve') {
            $updates['is_refunded'] = false;
        }

        if ($updates !== []) {
            $record->update($updates);
        }

        $this->syncContractAfterRefundDecision($record->fresh(), $action, $employee);

        return $this->adminLookupQuery()->findOrFail($record->id);
    }

    /**
     * Keep return order status on linked contract after admin decision.
     */
    protected function syncContractAfterRefundDecision(RefundableContract $record, string $action, ?Employee $employee = null): void
    {
        $contract = Contract::query()
            ->whereKey($record->contract_id)
            ->where('is_delete', 0)
            ->first();

        if (! $contract) {
            return;
        }

        // accept_retrun_contract* are deliberately NOT mass-assignable (see ContractMassAssignmentTest),
        // so `update()` silently dropped them and approvals never flagged the contract → forceFill.
        if ($action === 'retract') {
            $contract->forceFill([
                'contract_status_id' => ContractStatus::receivedId(),
                'accept_retrun_contract' => false,
                'accept_retrun_contract_employee_id' => null,
                'updated_at' => now(),
            ])->save();

            return;
        }

        if ($action === 'approve') {
            $contractUpdates = [
                'contract_status_id' => self::returnStatusId(),
                'accept_retrun_contract' => true,
                'updated_at' => now(),
            ];

            if ($employee) {
                $contractUpdates['accept_retrun_contract_employee_id'] = $employee->id;
            }

            $contract->forceFill($contractUpdates)->save();

            return;
        }

        // reject — stay in return status awaiting employee action or archive
        $contract->update([
            'contract_status_id' => self::returnStatusId(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Ensure a refundable request exists before setting contract to return status.
     */
    public function assertRefundableRequestExists(Contract $contract): void
    {
        $exists = RefundableContract::query()
            ->where('contract_id', $contract->id)
            ->where(function (Builder $q): void {
                $q->whereNull('admin_confirmed')
                    ->orWhere('admin_confirmed', true);
            })
            ->where('is_refunded', false)
            ->exists();

        // دفعة (د) — ب8: استرجاع ناجح عبر Moyasar يكفي أيضاً.
        if (! $exists && \App\Services\Payments\PaymentRefundService::refundedTotalFor($contract) <= 0) {
            throw new InvalidArgumentException(trans('api.refund_request_required_for_return_status'));
        }
    }
}
