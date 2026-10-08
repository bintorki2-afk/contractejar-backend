<?php

namespace App\Modules\Catalog\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ReaEstatType;
use App\Modules\Catalog\Requests\Admin\StoreReaEstatTypeRequest;
use App\Shared\Responses\Responser;

class TypeRealController extends Controller
{
    use Responser;

    public function index()
    {
        $this->authorize('viewAny', ReaEstatType::class);

        $types = ReaEstatType::latest()->paginate(20);

        return $this->apiResponse($types, trans('api.success'));
    }

    public function store(StoreReaEstatTypeRequest $request)
    {
        $this->authorize('create', ReaEstatType::class);

        $type = ReaEstatType::create([
            'contract_type' => $request->contract_type,
            'name_ar' => $request->name_ar,
        ]);

        return $this->apiResponse($type, trans('api.created_successfully'));
    }

    public function update(StoreReaEstatTypeRequest $request, $id)
    {
        $type = ReaEstatType::findOrFail($id);

        if (! $type) {
            return $this->apiResponse(null, trans('api.not_found'), 404);
        }

        $this->authorize('update', $type);

        $type->update([
            'contract_type' => $request->contract_type,
            'name_ar' => $request->name_ar,
        ]);

        return $this->apiResponse($type, trans('api.updated_successfully'));
    }

    public function destroy($id)
    {
        $type = ReaEstatType::find($id);

        if (! $type) {
            return $this->apiResponse(null, trans('api.not_found'), 404);
        }

        $this->authorize('delete', $type);

        // لا يُحذف صف مرجعي مرتبط بطلبات عملاء (كان الحذف يُسلسِل حذف الطلبات). (CROSS-0)
        if (\App\Support\LookupUsage::contractsReference('rea_estat_types', (int) $type->id)) {
            return response()->json(['message' => trans('api.lookup_in_use'), 'code' => 422, 'success' => false], 422);
        }

        $type->delete();

        return $this->apiResponse([], trans('api.deleted_successfully'));
    }
}
