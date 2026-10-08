<?php

namespace App\Modules\Contracts\Actions;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\Employee;
use App\Modules\Contracts\Services\AdminOrderQueryService;
use Illuminate\Http\Request;

class SetReturnContractAcceptanceAction
{
    public function __construct(
        private readonly AdminOrderQueryService $orders
    ) {}

    /**
     * @return array{ok: bool, contract?: Contract, message?: string, code?: int}
     */
    public function execute(Request $request, int $id, bool $accepted): array
    {
        if (! $request->user() instanceof Employee) {
            return ['ok' => false, 'message' => trans('api.unauthorized'), 'code' => 403];
        }

        /** @var Employee $employee */
        $employee = $request->user();
        $contract = $this->orders->findAdminContract($id);

        if (ContractStatus::refundedId() === null || (int) $contract->contract_status_id !== ContractStatus::refundedId()) {
            return ['ok' => false, 'message' => trans('api.order_not_in_return_status'), 'code' => 422];
        }

        $contract->forceFill([
            'accept_retrun_contract' => $accepted,
            'accept_retrun_contract_employee_id' => $employee->id,
        ])->save();

        return ['ok' => true, 'contract' => $contract];
    }
}
