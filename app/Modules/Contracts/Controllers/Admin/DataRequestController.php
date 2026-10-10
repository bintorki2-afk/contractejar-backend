<?php

namespace App\Modules\Contracts\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\Responser;
use App\Models\Contract;
use App\Models\ContractDataRequest;
use App\Models\Employee;
use App\Services\DataRequests\ContractDataRequestService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * دفعة (هـ) — 2.4: طلبات المرفق الناقص / التصحيح.
 */
class DataRequestController extends Controller
{
    use Responser;

    public function __construct(private readonly ContractDataRequestService $requests) {}

    /** GET /api/admin/data-requests/catalogue */
    public function catalogue()
    {
        return $this->apiResponse($this->requests->catalogue(), trans('api.success'));
    }

    /** GET /api/admin/orders/{id}/data-requests */
    public function index(int $id)
    {
        $contract = $this->contract($id);

        return $this->apiResponse([
            'items' => $this->requests->all($contract)->map(fn (ContractDataRequest $r) => $this->requests->toAdminArray($r))->values()->all(),
            'pending' => $this->requests->pendingForAdmin($contract),
            'data_request_pending' => $this->requests->pendingSummary($contract),
        ], trans('api.success'));
    }

    /** POST /api/admin/orders/{id}/data-requests { section, items: [keys], note? } */
    public function store(Request $request, int $id)
    {
        try {
            $data = $request->validate([
                'section' => ['required', 'in:lessor,property,tenant'],
                'items' => ['nullable', 'array', 'max:20'],
                'items.*' => ['string', 'max:60'],
                'note' => ['nullable', 'string', 'max:1000'],
            ], ['section.in' => 'القسم غير معروف: lessor | property | tenant']);

            $contract = $this->contract($id);
            $result = $this->requests->create($contract, $data['section'], $data['items'] ?? [], $data['note'] ?? null, $this->employee($request));

            return $this->apiResponse(array_merge($result, [
                'data_request_pending' => $this->requests->pendingSummary($contract),
            ]), 'تم تسجيل طلب المرفق الناقص وإشعار العميل.', true, 201);
        } catch (ValidationException $e) {
            return $this->validation($e);
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.contract_not_found'), 404);
        }
    }

    /** POST /api/admin/orders/{id}/data-requests/{rid}/resolve { note? } */
    public function resolve(Request $request, int $id, int $rid)
    {
        try {
            $contract = $this->contract($id);
            $row = $this->requests->resolve($contract, $this->row($contract, $rid), $this->employee($request), $request->input('note'));

            return $this->apiResponse(['request' => $this->requests->toAdminArray($row->fresh(['requester'])), 'data_request_pending' => $this->requests->pendingSummary($contract)], 'تم حل الطلب.');
        } catch (ValidationException $e) {
            return $this->validation($e);
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }
    }

    /** POST /api/admin/orders/{id}/data-requests/{rid}/cancel */
    public function cancel(Request $request, int $id, int $rid)
    {
        try {
            $contract = $this->contract($id);
            $row = $this->requests->cancel($contract, $this->row($contract, $rid), $this->employee($request));

            return $this->apiResponse(['request' => $this->requests->toAdminArray($row->fresh(['requester'])), 'data_request_pending' => $this->requests->pendingSummary($contract)], 'أُلغي الطلب.');
        } catch (ValidationException $e) {
            return $this->validation($e);
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }
    }

    /** POST /api/admin/orders/{id}/data-requests/{rid}/remind */
    public function remind(Request $request, int $id, int $rid)
    {
        try {
            $contract = $this->contract($id);
            $result = $this->requests->remind($contract, $this->row($contract, $rid), $this->employee($request));

            return $this->apiResponse($result, 'جُهّز التذكير — افتح واتساب لإرساله.');
        } catch (ValidationException $e) {
            return $this->validation($e);
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }
    }

    private function contract(int $id): Contract
    {
        return Contract::query()->where('is_delete', 0)->findOrFail($id);
    }

    private function row(Contract $contract, int $rid): ContractDataRequest
    {
        return ContractDataRequest::query()->where('contract_id', $contract->id)->findOrFail($rid);
    }

    private function employee(Request $request): ?Employee
    {
        return $request->user() instanceof Employee ? $request->user() : null;
    }

    private function validation(ValidationException $e)
    {
        return response()->json([
            'message' => collect($e->errors())->flatten()->first() ?? $e->getMessage(),
            'errors' => $e->errors(),
            'code' => 422,
            'success' => false,
        ], 422);
    }
}
