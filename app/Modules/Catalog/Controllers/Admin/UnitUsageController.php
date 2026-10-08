<?php

namespace App\Modules\Catalog\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\UnitUsage;
use App\Modules\Catalog\Requests\Admin\StoreNamedCatalogItemRequest;
use App\Modules\Catalog\Requests\Admin\UpdateNamedCatalogItemRequest;
use App\Shared\Responses\Responser;
use Illuminate\Http\Request;

class UnitUsageController extends Controller
{
    use Responser;

    public function index(Request $request)
    {
        $this->authorize('viewAny', UnitUsage::class);

        try {
            $query = UnitUsage::query();

            if ($request->filled('contract_type')) {
                $query->where('contract_type', $request->string('contract_type'));
            }

            if ($request->filled('search')) {
                $search = $request->string('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name_ar', 'like', "%{$search}%")
                      ->orWhere('name_en', 'like', "%{$search}%");
                });
            }

            $allowedSorts = ['id', 'name_ar', 'contract_type', 'created_at', 'updated_at'];
            $sortBy = $request->get('sort_by', 'created_at');
            $sortBy = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'created_at';

            $sortOrder = strtolower($request->get('sort_order', 'desc'));
            $sortOrder = in_array($sortOrder, ['asc', 'desc'], true) ? $sortOrder : 'desc';

            $perPage = (int) $request->get('per_page', 20);
            $perPage = $perPage > 0 ? $perPage : 20;

            $unitUsages = $query
                ->orderBy($sortBy, $sortOrder)
                ->paginate($perPage);

            return $this->apiResponse(
                [
                    'items' => $unitUsages->items(),
                    'pagination' => $this->paginate($unitUsages),
                ],
                trans('api.success')
            );
        } catch (\Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function store(StoreNamedCatalogItemRequest $request)
    {
        $this->authorize('create', UnitUsage::class);

        $unitUsage = UnitUsage::create($request->validated());

        return $this->apiResponse($unitUsage, trans('api.created_successfully'), 201);
    }

    public function show(int $id)
    {
        try {
            $unitUsage = UnitUsage::query()->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $this->authorize('view', $unitUsage);

        return $this->apiResponse($unitUsage, trans('api.success'));
    }

    public function update(UpdateNamedCatalogItemRequest $request, int $id)
    {
        try {
            $unitUsage = UnitUsage::query()->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $this->authorize('update', $unitUsage);
        $unitUsage->update($request->validated());

        return $this->apiResponse($unitUsage->fresh(), trans('api.updated_successfully'));
    }

    public function destroy(int $id)
    {
        try {
            $unitUsage = UnitUsage::query()->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $this->authorize('delete', $unitUsage);

        // لا يُحذف صف مرجعي مرتبط بطلبات عملاء (كان الحذف يُسلسِل حذف الطلبات). (CROSS-0)
        if (\App\Support\LookupUsage::contractsReference('unit_usages', (int) $unitUsage->id)) {
            return response()->json(['message' => trans('api.lookup_in_use'), 'code' => 422, 'success' => false], 422);
        }

        $unitUsage->delete();

        return $this->apiResponse([], trans('api.deleted_successfully'));
    }

    public function create()
    {
        $this->authorize('viewAny', UnitUsage::class);

        return $this->apiResponse(
            [
                'validation_rules' => (new StoreNamedCatalogItemRequest)->rules(),
            ],
            trans('api.success')
        );
    }
}
