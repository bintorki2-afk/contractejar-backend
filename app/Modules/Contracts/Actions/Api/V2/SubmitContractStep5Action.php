<?php

namespace App\Modules\Contracts\Actions\Api\V2;

use App\Http\Requests\Api\V2\Contract\Step5Request;
use App\Models\Contract;
use App\Services\ContractUnitsService;
use InvalidArgumentException;

class SubmitContractStep5Action
{
    public function __construct(
        private readonly ContractUnitsService $units,
    ) {}

    /**
     * @return array{ok: true, contract: Contract, units_count: int}|array{ok: false, message: string, code: int}
     */
    public function execute(Contract $contract, Step5Request $request, int $userId): array
    {
        if ($contract->lockedForCustomerStep(5)) {
            return ['ok' => false, 'message' => trans('api.completed_contract'), 'code' => 422];
        }

        $unitsPayload = $request->input('units', []);
        if (! is_array($unitsPayload) || $unitsPayload === []) {
            return ['ok' => false, 'message' => 'يجب إرسال وحدة واحدة على الأقل.', 'code' => 422];
        }

        try {
            $units = $this->units->syncForContract(
                $contract,
                $unitsPayload,
                $userId
            );
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'code' => 422];
        }

        // Meter fees (MeterFees / ContractPricing) are decided by the CONTRACT-level ownership
        // columns, but the v2 wizard only receives ownership per unit. Roll the units up so a
        // tenant-owned meter on any unit is actually priced (was silently never charged).
        $step5Data = ['step' => 6];
        foreach (['electricity_meter_ownership', 'water_meter_ownership'] as $ownershipColumn) {
            $values = collect($units)->pluck($ownershipColumn)->filter()->values();
            if ($values->isEmpty()) {
                continue;
            }
            // الأولوية: مستأجر (رسوم نقل) > مشترك (بند عقد) > مالك.
            $step5Data[$ownershipColumn] = $values->contains('tenant')
                ? 'tenant'
                : ($values->contains('shared') ? 'shared' : 'owner');
        }

        $contract->update($step5Data);

        return [
            'ok' => true,
            'contract' => $contract->fresh(['contractStatus', 'units.unitType', 'units.unitUsage', 'units.realEstate']),
            'units_count' => count($units),
        ];
    }
}
