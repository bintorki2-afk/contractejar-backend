<?php

namespace App\Modules\Contracts\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreContractStatusRequest;
use App\Http\Requests\Admin\UpdateContractStatusRequest;
use App\Http\Traits\Responser;
use App\Models\ContractStatus;
use Illuminate\Http\Request;

class ContractStatusController extends Controller
{
    use Responser;

    /**
     * Display a listing of contract statuses
     */
    public function index(Request $request)
    {
        try {
            // QA-F C13 / قرار E3: حالة «إرسال المسودة» الملغاة لا تظهر في إدارة الحالات.
            $query = ContractStatus::query()->where(fn ($q) => $q->whereNull('status_key')->orWhereNotIn('status_key', ContractStatus::LEGACY_KEYS));
            $contractStatuses = $query->paginate($this->perPageFromRequest($request));
            return $this->apiResponse(
                [
                    'items' => $contractStatuses->items(),
                    'pagination' => $this->paginate($contractStatuses),
                ],
                trans('api.success')
            );
        } catch (\Exception $e) {
            return $this->errorMessage(
                trans('api.error_occurred') . ': ' . $e->getMessage(),
                500
            );
        }
    }

  

    /**
     * Store a newly created contract status
     */
    public function store(StoreContractStatusRequest $request)
    {
        try {
            $contractStatus = ContractStatus::create($request->validated());

            return $this->apiResponse(
                $contractStatus,
                trans('api.created_successfully'),
                201
            );
        } catch (\Exception $e) {
            return $this->errorMessage(
                trans('api.error_occurred') . ': ' . $e->getMessage(),
                500
            );
        }
    }
 
     
    /**
     * Update the specified contract status
     */
    public function update(UpdateContractStatusRequest $request, $id)
    {
        try {
            $contractStatus = ContractStatus::find($id);

            if (!$contractStatus) {
                return $this->errorMessage(
                    trans('api.not_found'),
                    404
                );
            }

            // QA-F C13: الحالة الملغاة بيانات تاريخية فقط — لا تُعدّل ولا يُعاد تفعيلها.
            if (in_array($contractStatus->status_key, ContractStatus::LEGACY_KEYS, true)) {
                return $this->errorMessage('هذه الحالة ملغاة (مرحلة «إرسال المسودة») ولا يمكن تعديلها.', 422);
            }

            $contractStatus->update($request->validated());

            return $this->apiResponse(
                $contractStatus->fresh(),
                trans('api.updated_successfully')
            );
        } catch (\Exception $e) {
            return $this->errorMessage(
                trans('api.error_occurred') . ': ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Remove the specified contract status
     */
    public function destroy($id)
    {
        try {
            $contractStatus = ContractStatus::find($id);

            if (!$contractStatus) {
                return $this->errorMessage(
                    trans('api.not_found'),
                    404
                );
            }

            $contractStatus->delete();

            return $this->apiResponse(
                [],
                trans('api.deleted_successfully')
            );
        } catch (\Exception $e) {
            return $this->errorMessage(
                trans('api.error_occurred') . ': ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get all active contract statuses
     */
    public function active(Request $request)
    {
        try {
            $contractStatuses = ContractStatus::where('is_active', true)
                ->where(fn ($q) => $q->whereNull('status_key')->orWhereNotIn('status_key', ContractStatus::LEGACY_KEYS))
                ->orderBy('id', 'asc')
                ->get();

            return $this->apiResponse(
                $contractStatuses,
                trans('api.success')
            );
        } catch (\Exception $e) {
            return $this->errorMessage(
                trans('api.error_occurred') . ': ' . $e->getMessage(),
                500
            );
        }
    }
}
