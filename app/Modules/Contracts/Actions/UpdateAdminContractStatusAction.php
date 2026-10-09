<?php

namespace App\Modules\Contracts\Actions;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\DraftContractStatus;
use App\Modules\Contracts\Services\AdminOrderQueryService;
use App\Services\Admin\RefundableContractService;
use App\Services\ContractStatusCaseService;
use App\Services\ContractStatusHistoryService;
use App\Services\DraftBeforeNotarizationRule;
use App\Services\FirebaseNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateAdminContractStatusAction
{
    public function __construct(
        private readonly AdminOrderQueryService $orders,
        private readonly ContractStatusCaseService $caseService,
        private readonly ContractStatusHistoryService $history,
        private readonly FirebaseNotificationService $firebase,
        private readonly RefundableContractService $refundable,
        private readonly DraftBeforeNotarizationRule $draftRule,
    ) {}

    /**
     * @return array{ok: bool, contract?: Contract, message?: string, errors?: mixed, code?: int}
     */
    public function execute(Request $request, int $id): array
    {
        $statusId = $request->input('status_id')
            ?? $request->input('contract_status_id')
            ?? $request->input('draft_contract_status_id');

        if ($statusId === null || ! is_numeric($statusId)) {
            return [
                'ok' => false,
                'errors' => ['status_id' => ['status_id مطلوب.']],
                'code' => 422,
            ];
        }

        $contract = $this->orders->findAdminContract($id);
        $isDraft = $request->exists('is_draft')
            ? $request->boolean('is_draft')
            : (bool) $contract->is_draft;

        if ($isDraft) {
            $request->merge([
                'draft_contract_status_id' => (int) $statusId,
                'status_id' => (int) $statusId,
            ]);

            return $this->updateDraft($request, $contract);
        }

        $request->merge([
            'contract_status_id' => (int) $statusId,
            'status_id' => (int) $statusId,
        ]);

        return $this->updateLive($request, $contract);
    }

    /**
     * @return array{ok: bool, contract?: Contract, errors?: mixed, code?: int}
     */
    public function updateLive(Request $request, Contract $contract): array
    {
        $validator = Validator::make($request->all(), [
            'contract_status_id' => ['required', 'integer', 'exists:contract_statuses,id'],
        ]);

        if ($validator->fails()) {
            return ['ok' => false, 'errors' => $validator->errors(), 'code' => 422];
        }

        $statusId = (int) $request->contract_status_id;
        $status = ContractStatus::query()->find($statusId);

        // دفعة (د): «مسترجع» تُحدَّد بالمفتاح refunded؛ «قيد المراجعة» لم تعد تتطلب طلب استرجاع.
        $refundedId = ContractStatus::refundedId();
        if ($refundedId !== null && $statusId === $refundedId && (int) $contract->contract_status_id !== $refundedId) {
            $this->refundable->assertRefundableRequestExists($contract);
        }

        $this->assertStatusCase($request, $contract, $statusId, $status?->name);
        // ف2: لا توثيق قبل إرسال المسودة عبر واتساب (مدير النظام يتجاوز بـ force=1).
        $override = (int) $contract->contract_status_id !== $statusId
            ? $this->draftRule->assert($request, $contract, $statusId, $status?->name)
            : ['forced' => false, 'forced_by' => null];
        $this->persist($request, $contract, 'contract_status_id', $statusId, $status?->name, $override);

        return ['ok' => true, 'contract' => $contract];
    }

    /**
     * @return array{ok: bool, contract?: Contract, message?: string, errors?: mixed, code?: int}
     */
    public function updateDraft(Request $request, Contract $contract): array
    {
        $validator = Validator::make($request->all(), [
            'draft_contract_status_id' => ['required', 'integer', 'exists:draft_contract_statuses,id'],
        ]);

        if ($validator->fails()) {
            return ['ok' => false, 'errors' => $validator->errors(), 'code' => 422];
        }

        if (! $contract->is_draft) {
            return ['ok' => false, 'message' => 'العقد ليس مسودة.', 'code' => 422];
        }

        $statusId = (int) $request->draft_contract_status_id;
        $status = DraftContractStatus::query()->find($statusId);

        $this->assertStatusCase($request, $contract, $statusId, $status?->name);
        $this->persist($request, $contract, 'draft_contract_status_id', $statusId, $status?->name);

        return ['ok' => true, 'contract' => $contract];
    }

    private function assertStatusCase(Request $request, Contract $contract, int $statusId, ?string $statusName): void
    {
        $contract->loadMissing('user');

        $validator = Validator::make(
            $request->all(),
            $this->caseService->rules($statusId, $statusName),
            $this->caseService->messages()
        );
        $validator->after(function ($validator) use ($request, $statusId, $statusName, $contract) {
            $this->caseService->afterValidation($validator, $request, $statusId, $statusName, $contract);
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * @param  array{forced: bool, forced_by: int|null}  $override
     */
    private function persist(
        Request $request,
        Contract $contract,
        string $statusColumn,
        int $statusId,
        ?string $statusName,
        array $override = ['forced' => false, 'forced_by' => null]
    ): void {
        $extra = $this->caseService->extract($request, $contract, $statusId, $statusName);
        $beforeId = $contract->{$statusColumn};

        $contract->update(array_merge(
            [$statusColumn => $statusId],
            $extra
        ));
        $contract->load($this->orders->contractDetailRelations());

        // دفعة (د) — ب9: سجل النشاط.
        if ((int) $beforeId !== $statusId) {
            $employee = $request->user() instanceof \App\Models\Employee ? $request->user() : null;
            $isCancel = $statusColumn === 'contract_status_id' && ContractStatus::keyForId($statusId) === ContractStatus::KEY_CANCELLED;
            app(\App\Services\Orders\OrderFlowService::class)->activity(
                $contract,
                $isCancel ? 'cancelled' : 'status_changed',
                $employee,
                [$statusColumn => $beforeId],
                array_merge([$statusColumn => $statusId], array_map(static fn ($v) => is_scalar($v) || $v === null ? $v : '[ملف]', $extra)),
                'employee',
                $override['forced'] ? 'تجاوز قاعدة المسودة (force)' : null,
            );
        }

        try {
            $meta = $this->caseService->historyMeta($statusId, $statusName, $extra) ?? [];
            if ($override['forced']) {
                $meta['draft_rule_forced'] = true;
                $meta['draft_rule_forced_by'] = $override['forced_by'];
            }
            $this->history->record($contract, [
                'source' => 'admin',
                'meta' => $meta !== [] ? $meta : null,
            ]);
            $this->firebase->notifyContractStatusChanged($contract);
        } catch (\Throwable $notifyError) {
            report($notifyError);
        }
    }
}
