<?php

namespace App\Modules\Contracts\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEmployeeContractReceivedRequest;
use App\Http\Requests\Admin\UpdateEmployeeContractReceivedRequest;
use App\Http\Resources\Admin\V2\Api\ReceivedContractResource;
use App\Http\Traits\Responser;
use App\Enums\ReceivedContractStatus;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\ReceivedContract;
use App\Services\Orders\OrderFlowService;
use Illuminate\Database\QueryException;
use Throwable;

class ReceivedContractController extends Controller
{
    use Responser;

    /**
     * Get the received-contract row for a contract (if any).
     */
    public function show(int $contractId)
    {
        if (! request()->user() instanceof Employee) {
            return $this->errorMessage(trans('api.unauthorized'), 403);
        }

        $received = ReceivedContract::query()
            ->where('contract_id', $contractId)
            ->with('employee')
            ->first();

        if (! $received) {
            return $this->errorMessage(trans('api.received_contract_not_found'), 404);
        }

        return $this->apiResponse(
            new ReceivedContractResource($received),
            trans('api.success')
        );
    }

    /**
     * Register receipt of a contract (one row per contract, first time only).
     * `employee_id` is always the authenticated employee (Bearer token).
     */
    public function store(StoreEmployeeContractReceivedRequest $request)
    {
        try {
            if (! $request->user() instanceof Employee) {
                return $this->errorMessage(trans('api.unauthorized'), 403);
            }

            $contractId = $request->integer('contract_id');

            $contract = Contract::query()
                ->whereKey($contractId)
                ->where('is_delete', false)
                ->first(['id', 'contract_status_id']);

            if (! $contract) {
                return $this->errorMessage(trans('api.contract_not_found'), 404);
            }

            $validated = $request->validated();
            // Receiving the contract marks it as finish (مستلم), not pending.
            $status = ReceivedContractStatus::Finish;
            if (array_key_exists('status', $validated) && $validated['status'] !== null) {
                $status = $validated['status'] instanceof ReceivedContractStatus
                    ? $validated['status']
                    : ReceivedContractStatus::from((string) $validated['status']);
            }

            /** @var Employee $employee */
            $employee = $request->user();

            $attributes = [
                'status' => $status,
                'date_of_received' => $request->filled('date_of_received')
                    ? $request->date('date_of_received')->format('Y-m-d')
                    : now()->toDateString(),
            ];
            if ($request->has('notes')) {
                $attributes['notes'] = $request->input('notes');
            }

            // دفعة (د): الاستلام متاح للطلبات «جديد» / «تم الدفع» / «قيد المراجعة»، ويضع الحالة «مستلم من الموظف».
            try {
                $result = app(OrderFlowService::class)->receive(Contract::query()->findOrFail($contractId), $employee, $attributes);
            } catch (QueryException $queryException) {
                if ($this->isMissingContractForeignKeyViolation($queryException)) {
                    return $this->errorMessage(trans('api.contract_not_found'), 404);
                }

                throw $queryException;
            }

            if (! $result['ok']) {
                if (($result['code'] ?? 0) === 409 && isset($result['existing'])) {
                    return $this->contractAlreadyReceivedResponse($result['existing']);
                }

                return $this->errorMessage((string) ($result['message'] ?? ''), (int) ($result['code'] ?? 422));
            }

            $received = $result['received'];
            $received->load('employee');

            return $this->apiResponse(
                new ReceivedContractResource($received),
                trans('api.contract_received_successfully')
            );
        } catch (Throwable $throwable) {
            return $this->errorMessage(
                trans('api.error_occurred') . ': ' . $throwable->getMessage(),
                500
            );
        }
    }

    /**
     * Update fields on an existing received-contract row (`status` accepts Arabic e.g. مستلم → finish).
     */
    public function update(UpdateEmployeeContractReceivedRequest $request, int $contractId)
    {
        try {
            if (! $request->user() instanceof Employee) {
                return $this->errorMessage(trans('api.unauthorized'), 403);
            }

            $received = ReceivedContract::query()
                ->where('contract_id', $contractId)
                ->first();

            if (! $received) {
                return $this->errorMessage(trans('api.received_contract_not_found'), 404);
            }

            $validated = $request->validated();
            $updates = [];

            if (array_key_exists('status', $validated)) {
                $updates['status'] = $validated['status'] instanceof ReceivedContractStatus
                    ? $validated['status']
                    : ReceivedContractStatus::from((string) $validated['status']);
            }
            if (array_key_exists('date_of_received', $validated)) {
                $updates['date_of_received'] = $validated['date_of_received'];
            }
            if (array_key_exists('notes', $validated)) {
                $updates['notes'] = $validated['notes'];
            }

            if ($updates !== []) {
                $received->fill($updates);
                $received->save();
            }

            $received->load('employee');

            return $this->apiResponse(
                new ReceivedContractResource($received),
                trans('api.success')
            );
        } catch (Throwable $throwable) {
            return $this->errorMessage(
                trans('api.error_occurred') . ': ' . $throwable->getMessage(),
                500
            );
        }
    }

    /**
     * Conflict: a receipt row already exists for this contract (any employee).
     * `data` uses the same shape as a successful store (ReceivedContractResource).
     */
    private function contractAlreadyReceivedResponse(ReceivedContract $receivedContract)
    {
        $receivedContract->loadMissing('employee');

        return response()->json([
            'message' => trans('api.contract_already_received'),
            'code' => 409,
            'success' => false,
            'data' => (new ReceivedContractResource($receivedContract))->resolve(request()),
        ], 409);
    }

    private function isUniqueContractIdViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        if ($sqlState === '23505') {
            return true;
        }

        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $driverCode === 1062 || $driverCode === 19;
    }

    private function isMissingContractForeignKeyViolation(QueryException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($driverCode !== 1452) {
            return false;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'received_contracts_contract_id_foreign')
            || str_contains($message, '`contract_id`');
    }
}
