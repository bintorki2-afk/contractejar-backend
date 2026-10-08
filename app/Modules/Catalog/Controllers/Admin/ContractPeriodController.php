<?php

namespace App\Modules\Catalog\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ContractPeriod;
use App\Modules\Catalog\Requests\Admin\StoreContractPeriodRequest;
use App\Shared\Responses\Responser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ContractPeriodController extends Controller
{
    use Responser;

    public function index(Request $request)
    {
        $this->authorize('viewAny', ContractPeriod::class);

        $query = ContractPeriod::query();

        if ($request->filled('contract_type')) {
            $query->where('contract_type', $request->contract_type);
        }

        $contracts = $query->latest()->paginate($request->get('per_page', 20));

        return $this->apiResponse($contracts, trans('api.success'));
    }

    public function show($id)
    {
        $contractPeriod = ContractPeriod::find($id);

        if (! $contractPeriod) {
            return $this->apiResponse(null, trans('api.contract_period_not_found'), false, 404);
        }

        $this->authorize('view', $contractPeriod);

        return $this->apiResponse($contractPeriod, trans('api.success'));
    }

    public function create(Request $request)
    {
        $this->authorize('create', ContractPeriod::class);

        $validator = Validator::make($request->all(), (new StoreContractPeriodRequest)->rules());

        if ($validator->fails()) {
            return $this->apiResponse(null, $validator->errors()->first(), false, 422);
        }

        $contractPeriod = ContractPeriod::create($this->prepareContractPeriodData($request));

        return $this->apiResponse($contractPeriod, trans('api.contract_period_created_successfully'), true, 201);
    }

    public function store(Request $request)
    {
        return $this->create($request);
    }

    public function update(Request $request, $id)
    {
        $contractPeriod = ContractPeriod::find($id);

        if (! $contractPeriod) {
            return $this->apiResponse(null, trans('api.contract_period_not_found'), false, 404);
        }

        $this->authorize('update', $contractPeriod);

        $validator = Validator::make($request->all(), (new StoreContractPeriodRequest)->rules());

        if ($validator->fails()) {
            return $this->apiResponse(null, $validator->errors()->first(), false, 422);
        }

        $contractPeriod->update($this->prepareContractPeriodData($request));

        return $this->apiResponse($contractPeriod, trans('api.contract_period_updated_successfully'));
    }

    public function destroy($id)
    {
        $contractPeriod = ContractPeriod::find($id);

        if (! $contractPeriod) {
            return $this->apiResponse(null, trans('api.contract_period_not_found'), false, 404);
        }

        $this->authorize('delete', $contractPeriod);

        // منع حذف مدة مرتبطة بطلبات (الحذف يُسلسِل حذف العقود على MySQL). (cascade)
        $inUse = \App\Models\Contract::query()
            ->where(function ($q) use ($contractPeriod) {
                $q->where('contract_term_in_years', $contractPeriod->id)
                    ->orWhere('contract_period_id', $contractPeriod->id);
            })
            ->exists();

        if ($inUse) {
            return $this->apiResponse(null, trans('api.contract_period_in_use'), false, 422);
        }

        $contractPeriod->delete();

        return $this->apiResponse(null, trans('api.contract_period_deleted_successfully'));
    }

    private function prepareContractPeriodData(Request $request): array
    {
        return [
            'period' => $request->period,
            'note_ar' => $request->note_ar,
            'note_en' => $request->note_en,
            'contract_type' => $request->contract_type,
            'price' => $request->price ?? null,
        ];
    }
}
