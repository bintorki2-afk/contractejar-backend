<?php

namespace App\Modules\Contracts\Actions\Api\V2;

use App\Http\Resources\Api\V2\Contract\Step1Resource;
use App\Http\Resources\Api\V2\Contract\Step2Resource;
use App\Http\Resources\Api\V2\Contract\Step3Resource;
use App\Http\Resources\Api\V2\Contract\Step4Resource;
use App\Http\Resources\Api\V2\Contract\Step5Resource;
use App\Http\Resources\Api\V2\Contract\Step6Resource;
use App\Models\Contract;

class LoadUncompletedContractStepsAction
{
    /**
     * @return array{ok: true, contract: Contract, data: array<string, mixed>}|array{ok: false, message: string, code: int}
     */
    public function execute(string $uuid, int $userId): array
    {
        $contract = Contract::query()
            ->where('user_id', $userId)
            ->where('uuid', $uuid)
            ->where('is_delete', false)
            ->with([
                'realEstate',
                'contractTermInYears',
                'contractStatus',
                'draftContractStatus',
                'units.unitType',
                'units.unitUsage',
                'units.realEstate',
            ])
            ->first();

        if (! $contract) {
            return ['ok' => false, 'message' => trans('api.contract_not_found'), 'code' => 404];
        }

        // دفعة (هـ) — E4: طلب مدفوع له طلب مرفق ناقص معلّق ⇒ وضع التصحيح: كل الخطوات المطبّقة (1..6) للتعبئة المسبقة.
        $dataRequests = app(\App\Services\DataRequests\ContractDataRequestService::class);
        $pending = (bool) $contract->is_completed ? $dataRequests->pendingForCustomer($contract) : [];
        $fixMode = (bool) $contract->is_completed && $pending !== [];

        if ($contract->is_completed && ! $fixMode) {
            return ['ok' => false, 'message' => trans('api.completed_contract'), 'code' => 400];
        }

        $data = array_merge([
            'step' => (int) $contract->step,
            'contract_id' => $contract->id,
            'uuid' => (string) $contract->uuid,
            'fix_mode' => $fixMode,
            'pending_data_requests' => $pending,
            'editable_steps' => $fixMode ? array_values(array_unique(array_merge(...array_map(static fn ($r) => $r['steps'], $pending)))) : [],
        ], $this->buildPreviousStepsData($contract, $fixMode));

        return ['ok' => true, 'contract' => $contract, 'data' => $data];
    }

    /**
     * @return list<int>
     */
    private function applicableStepNumbers(Contract $contract): array
    {
        if (! Contract::shouldSkipInitialSteps($contract->instrument_type)) {
            return [1, 2, 3, 4, 5, 6];
        }

        if ($contract->instrument_type === 'lease_renewal') {
            return [3, 5, 6];
        }

        return [3, 4, 5, 6];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPreviousStepsData(Contract $contract, bool $allSteps = false): array
    {
        $currentStep = (int) $contract->step;
        $data = [];

        foreach ($this->applicableStepNumbers($contract) as $stepNumber) {
            if (! $allSteps && $stepNumber >= $currentStep) {
                break;
            }

            $data['step'.$stepNumber] = $this->resolveStepPayload($stepNumber, $contract);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|Step1Resource|Step2Resource|Step3Resource|Step4Resource|Step5Resource|Step6Resource
     */
    private function resolveStepPayload(int $stepNumber, Contract $contract): mixed
    {
        return match ($stepNumber) {
            1 => new Step1Resource($contract),
            2 => new Step2Resource($contract),
            3 => new Step3Resource($contract),
            4 => new Step4Resource($contract),
            5 => new Step5Resource($contract),
            6 => new Step6Resource($contract->loadMissing('contractTermInYears')),
            default => [],
        };
    }
}
