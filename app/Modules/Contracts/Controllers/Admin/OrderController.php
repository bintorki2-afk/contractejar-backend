<?php

namespace App\Modules\Contracts\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateContractRequest;
use App\Http\Requests\Admin\UpdateReturnContractAcceptanceRequest;
use App\Http\Resources\Admin\V2\Api\OrderResource;
use App\Http\Traits\Responser;
use App\Models\Contract;
use App\Models\Payment;
use App\Modules\Contracts\Actions\SetReturnContractAcceptanceAction;
use App\Modules\Contracts\Actions\UpdateAdminContractAction;
use App\Modules\Contracts\Actions\UpdateAdminContractStatusAction;
use App\Modules\Contracts\Services\AdminOrderDetailService;
use App\Modules\Contracts\Services\AdminOrderQueryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class OrderController extends Controller
{
    use Responser;

    public function __construct(
        private readonly AdminOrderQueryService $orders,
        private readonly AdminOrderDetailService $details,
        private readonly UpdateAdminContractStatusAction $updateStatus,
        private readonly UpdateAdminContractAction $updateContract,
        private readonly SetReturnContractAcceptanceAction $returnAcceptance,
    ) {}

    /**
     * Single orders list. Filter with query params — do not use a second list URL.
     *
     * GET /api/admin/orders
     */
    public function orders(Request $request)
    {
        try {
            $result = $this->orders->paginateOrders($request);

            return $this->paginatedApiResponse(
                $result['paginator'],
                OrderResource::collection($result['paginator']),
                trans('api.success'),
                $result['meta']
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->apiResponse(
                null,
                trans('api.error_occurred').': '.$e->getMessage(),
                false,
                500
            );
        }
    }

    public function returnOrders(Request $request)
    {
        try {
            $result = $this->orders->paginateReturnOrders($request);

            return $this->paginatedApiResponse(
                $result['paginator'],
                OrderResource::collection($result['paginator']),
                trans('api.success'),
                $result['meta']
            );
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->apiResponse(
                null,
                trans('api.error_occurred').': '.$e->getMessage(),
                false,
                500
            );
        }
    }

    public function receivedOrders(Request $request)
    {
        try {
            $result = $this->orders->paginateReceivedOrders($request);

            return $this->paginatedApiResponse(
                $result['paginator'],
                OrderResource::collection($result['paginator']),
                trans('api.success'),
                $result['meta']
            );
        } catch (\Throwable $e) {
            return $this->apiResponse(
                null,
                trans('api.error_occurred').': '.$e->getMessage(),
                false,
                500
            );
        }
    }

    public function byStatus(Request $request, $statusId)
    {
        $request->merge(['status_id' => $statusId]);

        return $this->orders($request);
    }

    public function draftByStatus(Request $request, $statusId)
    {
        $request->merge([
            'is_draft' => 1,
            'status_id' => $statusId,
            'draft_contract_status_id' => $statusId,
        ]);

        return $this->orders($request);
    }

    public function incomplete(Request $request)
    {
        $request->merge(['incomplete' => 1]);

        return $this->orders($request);
    }

    public function draftContracts(Request $request)
    {
        try {
            $result = $this->orders->paginateDraftOrders($request);

            return $this->paginatedApiResponse(
                $result['paginator'],
                OrderResource::collection($result['paginator']),
                trans('api.success'),
                $result['meta']
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->apiResponse(
                null,
                trans('api.error_occurred').': '.$e->getMessage(),
                false,
                500
            );
        }
    }

    public function completedAndDraft(Request $request)
    {
        try {
            $result = $this->orders->paginateCompletedAndDraft($request);

            return $this->paginatedApiResponse(
                $result['paginator'],
                OrderResource::collection($result['paginator']),
                trans('api.success'),
                $result['meta']
            );
        } catch (\Throwable $e) {
            return $this->apiResponse(
                null,
                trans('api.error_occurred').': '.$e->getMessage(),
                false,
                500
            );
        }
    }

    public function complete(Request $request)
    {
        $request->merge(['complete' => 1]);

        return $this->orders($request);
    }

    public function show(Request $request, $id)
    {
        $contract = $this->orders->findAdminContract((int) $id);

        return $this->apiResponse(
            $this->details->fullPayload($contract, $request),
            trans('api.success')
        );
    }

    public function updateStatus(Request $request, $id)
    {
        return $this->handleStatusOutcome(
            fn () => $this->updateStatus->execute($request, (int) $id),
            $request
        );
    }

    public function updateContractStatus(Request $request, $id)
    {
        return $this->handleStatusOutcome(function () use ($request, $id) {
            $contract = $this->orders->findAdminContract((int) $id);

            return $this->updateStatus->updateLive($request, $contract);
        }, $request);
    }

    public function updateDraftContractStatus(Request $request, $id)
    {
        return $this->handleStatusOutcome(function () use ($request, $id) {
            $contract = $this->orders->findAdminContract((int) $id);

            return $this->updateStatus->updateDraft($request, $contract);
        }, $request);
    }

    public function update(UpdateContractRequest $request, $id)
    {
        try {
            $contract = $this->updateContract->execute($request, (int) $id);

            return $this->apiResponse(
                $this->details->fullPayload($contract, $request),
                trans('api.contract_updated_successfully')
            );
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(null, trans('api.contract_not_found'), false, 404);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (\Exception $e) {
            report($e);

            return $this->apiResponse(
                null,
                trans('api.error_occurred').(config('app.debug') ? ': '.$e->getMessage() : ''),
                false,
                500
            );
        }
    }

    public function updateReturnContractAcceptance(UpdateReturnContractAcceptanceRequest $request, int $id)
    {
        try {
            $outcome = $this->returnAcceptance->execute(
                $request,
                $id,
                $request->boolean('accept_retrun_contract')
            );

            if (! $outcome['ok']) {
                return $this->errorMessage($outcome['message'], $outcome['code'] ?? 422);
            }

            return $this->apiResponse(
                $this->details->fullPayload($outcome['contract'], $request),
                $request->boolean('accept_retrun_contract')
                    ? trans('api.return_contract_accepted_successfully')
                    : trans('api.return_contract_rejected_successfully')
            );
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(null, trans('api.contract_not_found'), false, 404);
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    /**
     * Delete a single order (contract) and its related rows.
     *
     * Related tables that reference contracts.id are removed automatically by
     * the database ON DELETE CASCADE / SET NULL foreign keys. Payment rows are
     * linked by contract_uuid (no FK), so we remove them explicitly. Everything
     * runs inside a transaction: if any step fails, nothing is deleted.
     *
     * POST /api/admin/orders/{id}/delete
     */
    public function destroy(Request $request, $id)
    {
        try {
            $contract = $this->orders->findAdminContract((int) $id);

            DB::transaction(function () use ($contract) {
                if (! empty($contract->uuid)) {
                    Payment::where('contract_uuid', $contract->uuid)->delete();
                }

                $contract->delete();
            });

            return $this->apiResponse(null, trans('api.success'), true, 200);
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(null, trans('api.contract_not_found'), false, 404);
        } catch (\Throwable $e) {
            return $this->apiResponse(
                null,
                trans('api.error_occurred').': '.$e->getMessage(),
                false,
                500
            );
        }
    }

    /**
     * @param  callable(): array{ok: bool, contract?: Contract, message?: string, errors?: mixed, code?: int}  $callback
     */
    private function handleStatusOutcome(callable $callback, Request $request)
    {
        try {
            $outcome = $callback();

            if (! $outcome['ok']) {
                if (! empty($outcome['errors'])) {
                    return $this->errorResponse($outcome['errors'], $outcome['code'] ?? 422);
                }

                return $this->errorMessage($outcome['message'] ?? trans('api.error_occurred'), $outcome['code'] ?? 422);
            }

            return $this->apiResponse(
                $this->details->fullPayload($outcome['contract'], $request),
                trans('api.updated_successfully')
            );
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(null, trans('api.contract_not_found'), false, 404);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->apiResponse(
                null,
                trans('api.error_occurred').': '.$e->getMessage(),
                false,
                500
            );
        }
    }
}
