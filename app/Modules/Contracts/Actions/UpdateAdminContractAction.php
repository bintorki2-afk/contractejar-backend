<?php

namespace App\Modules\Contracts\Actions;

use App\Http\Requests\Admin\UpdateContractRequest;
use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\DraftContractStatus;
use App\Modules\Contracts\Services\AdminOrderQueryService;
use App\Services\ContractStatusCaseService;
use App\Services\ContractStatusHistoryService;
use App\Services\DraftBeforeNotarizationRule;
use App\Services\FirebaseNotificationService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateAdminContractAction
{
    public function __construct(
        private readonly AdminOrderQueryService $orders,
        private readonly ContractStatusCaseService $caseService,
        private readonly ContractStatusHistoryService $history,
        private readonly FirebaseNotificationService $firebase,
        private readonly DraftBeforeNotarizationRule $draftRule,
    ) {}

    public function execute(UpdateContractRequest $request, int $id): Contract
    {
        $contract = $this->orders->findAdminContract($id);

        $payload = $request->updatePayload();
        $caseExtra = [];
        $override = ['forced' => false, 'forced_by' => null];

        if (array_key_exists('contract_status_id', $payload)) {
            $statusId = (int) $payload['contract_status_id'];
            $status = ContractStatus::query()->find($statusId);
            $this->assertStatusCase($request, $contract, $statusId, $status?->name);
            if ((int) $contract->contract_status_id !== $statusId) {
                // ف2: لا توثيق قبل إرسال المسودة عبر واتساب (مدير النظام يتجاوز بـ force=1).
                $override = $this->draftRule->assert($request, $contract, $statusId, $status?->name);
            }
            $extracted = $this->caseService->extract($request, $contract, $statusId, $status?->name);
            $caseExtra = array_merge($caseExtra, $extracted);
            $payload = array_merge($payload, $extracted);
        }

        if (array_key_exists('draft_contract_status_id', $payload)) {
            $statusId = (int) $payload['draft_contract_status_id'];
            $status = DraftContractStatus::query()->find($statusId);
            $this->assertStatusCase($request, $contract, $statusId, $status?->name);
            $extracted = $this->caseService->extract($request, $contract, $statusId, $status?->name);
            $caseExtra = array_merge($caseExtra, $extracted);
            $payload = array_merge($payload, $extracted);
        }

        $statusFields = ['contract_status_id', 'draft_contract_status_id', 'draft_before_paid', 'draft_after_paid'];
        $statusChanged = false;
        foreach ($statusFields as $field) {
            if (array_key_exists($field, $payload)
                && (string) ($contract->{$field} ?? '') !== (string) ($payload[$field] ?? '')
            ) {
                $statusChanged = true;
                break;
            }
        }

        $beforeStatusId = $contract->contract_status_id;
        $diff = \App\Services\Orders\ContractActivityLogger::diff($contract, $payload, $statusFields);

        $contract->fill($payload);
        $contract->save();
        $contract->refresh();

        // دفعة (د) — ب9: سجل النشاط (تعديل البيانات + تغيير الحالة).
        $flow = app(\App\Services\Orders\OrderFlowService::class);
        if ($diff['after'] !== []) {
            $flow->activity($contract, 'edited', $request->user() instanceof \App\Models\Employee ? $request->user() : null, $diff['before'], $diff['after']);
        }
        if ((int) $beforeStatusId !== (int) $contract->contract_status_id) {
            $flow->activity($contract, ContractStatus::keyForId((int) $contract->contract_status_id) === ContractStatus::KEY_CANCELLED ? 'cancelled' : 'status_changed',
                $request->user() instanceof \App\Models\Employee ? $request->user() : null,
                ['contract_status_id' => $beforeStatusId], ['contract_status_id' => $contract->contract_status_id]);
        }

        if ($statusChanged) {
            try {
                $contract->loadMissing(['contractStatus', 'draftContractStatus']);
                $statusId = (int) ($contract->is_draft
                    ? $contract->draft_contract_status_id
                    : $contract->contract_status_id);
                $statusName = $contract->is_draft
                    ? $contract->draftContractStatus?->name
                    : $contract->contractStatus?->name;
                $meta = $this->caseService->historyMeta($statusId, $statusName, $caseExtra) ?? [];
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

        return $contract;
    }

    private function assertStatusCase($request, Contract $contract, int $statusId, ?string $statusName): void
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
}
