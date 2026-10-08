<?php

namespace App\Modules\Contracts\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreContractPaidByEmployeeRequest;
use App\Http\Resources\Admin\V2\Api\ContractPaidByEmployeeResource;
use App\Http\Traits\Responser;
use App\Interfaces\PaymentGatewayInterface;
use App\Models\Contract;
use App\Models\ContractPaidByEmployee;
use App\Models\Employee;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ContractPaidByEmployeeController extends Controller
{
    use Responser;

    public function __construct(
        protected PaymentGatewayInterface $paymentService
    ) {}

    /**
     * GET /api/admin/contract-paid-by-employees
     */
    public function index(Request $request)
    {
        if (! $request->user() instanceof Employee) {
            return $this->errorMessage(trans('api.unauthorized'), 403);
        }

        $query = ContractPaidByEmployee::query()
            ->with(['employee', 'contractPeriod'])
            ->latest('id');

        if ($request->filled('contract_uuid')) {
            $query->where('contract_uuid', $request->string('contract_uuid'));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->has('is_paid')) {
            $query->where('is_paid', $request->boolean('is_paid'));
        }

        if ($request->filled('contract_type')) {
            $query->where('contract_type', $request->string('contract_type'));
        }

        if ($request->filled('draft_contract_number')) {
            $query->where('draft_contract_number', $request->string('draft_contract_number'));
        }

        if ($request->filled('customer_mobile')) {
            $query->where('customer_mobile', 'like', '%'.$request->string('customer_mobile').'%');
        }

        $records = $query->paginate($this->perPageFromRequest($request));

        return $this->paginatedApiResponse(
            $records,
            ContractPaidByEmployeeResource::collection($records)
        );
    }

    /**
     * POST /api/admin/contract-paid-by-employees
     *
     * Defaults: employee_id from auth, is_paid = false.
     * Returns payment link (ClickPay).
     */
    public function store(StoreContractPaidByEmployeeRequest $request)
    {
        try {
            $employee = $request->user();
            if (! $employee instanceof Employee) {
                return $this->errorMessage(trans('api.unauthorized'), 403);
            }

            $validated = $request->validated();
            $amount = (float) $validated['amount'];
            $contractUuid = (string) Contract::generateUUID();
            $draftContractId = $request->input('draft_contract_id')
                ?? ($validated['draft_contract_id'] ?? null);

            $record = ContractPaidByEmployee::query()->create([
                'contract_uuid' => $contractUuid,
                'employee_id' => $employee->id,
                'customer_mobile' => $validated['customer_mobile'],
                'contract_type' => $validated['contract_type'],
                'contract_period_id' => $validated['contract_period_id'],
                'draft_contract_number' => $validated['draft_contract_number'],
                'draft_contract_id' => $draftContractId,
                'amount' => $amount,
                'is_paid' => false,
                'notes' => $validated['notes'] ?? null,
            ]);

            // سجل تدقيق: من أنشأ الرابط وبأي مبلغ، ومقارنته بالرسم الرسمي للمدة. (CROSS-7)
            try {
                $period = \App\Models\ContractPeriod::query()->find($validated['contract_period_id']);
                $months = (int) ($period->months ?? 12) ?: 12;
                $official = \App\Support\DocFee::amount($months, (string) $validated['contract_type']);
            } catch (\Throwable) {
                $official = null;
            }
            $logContext = [
                'employee_id' => $employee->id,
                'contract_uuid' => $contractUuid,
                'amount' => $amount,
                'official_doc_fee' => $official,
                'contract_type' => $validated['contract_type'],
                'contract_period_id' => $validated['contract_period_id'],
            ];
            if ($official !== null && abs($official - $amount) > 0.01) {
                \Illuminate\Support\Facades\Log::warning('Employee payment link with a manual amount (differs from official fee)', $logContext);
            } else {
                \Illuminate\Support\Facades\Log::info('Employee payment link created', $logContext);
            }

            try {
                $payment = $this->paymentService->requestPaymentRedirectUrlWithoutContract(
                    $contractUuid,
                    $amount
                );
            } catch (\Throwable $e) {
                $record->delete();
                throw $e;
            }

            $record->load(['employee', 'contractPeriod']);

            return $this->apiResponse([
                'record' => new ContractPaidByEmployeeResource($record),
                'payment_url' => $payment['payment_url'],
                'Payment_url' => $payment['payment_url'],
                'cart_amount' => $payment['cart_amount'],
                'contract_uuid' => $payment['contract_uuid'],
                'payment_success_url' => $payment['payment_success_url'] ?? null,
                'payment_error_url' => $payment['payment_error_url'] ?? null,
            ], trans('api.created_successfully'), 201);
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.contract_not_found'), 404);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (\InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->apiResponse([
                'message' => trans('api.not_accept'),
                'gateway_error' => $e->getMessage(),
                'success' => false,
            ], trans('api.not_accept'), 400);
        } catch (\Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/contract-paid-by-employees/{id}/payment-link
     */
    public function paymentLink(Request $request, int $id)
    {
        if (! $request->user() instanceof Employee) {
            return $this->errorMessage(trans('api.unauthorized'), 403);
        }

        try {
            $record = ContractPaidByEmployee::query()->findOrFail($id);

            if ($record->contract_uuid === null || $record->contract_uuid === '') {
                return $this->errorMessage(trans('api.payment_link_unavailable'), 422);
            }

            return $this->paymentService->createPaymentUrlResponse((string) $record->contract_uuid);
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        } catch (\Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/contract-paid-by-employees/{id}
     */
    public function show(Request $request, int $id)
    {
        if (! $request->user() instanceof Employee) {
            return $this->errorMessage(trans('api.unauthorized'), 403);
        }

        try {
            $record = ContractPaidByEmployee::query()
                ->with(['employee', 'contractPeriod'])
                ->findOrFail($id);

            return $this->apiResponse(
                new ContractPaidByEmployeeResource($record),
                trans('api.success')
            );
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }
    }
}
