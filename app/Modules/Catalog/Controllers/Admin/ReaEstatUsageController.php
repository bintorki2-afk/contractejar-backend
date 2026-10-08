<?php

namespace App\Modules\Catalog\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ReaEstatUsage;
use App\Modules\Catalog\Requests\Admin\StoreNamedCatalogItemRequest;
use App\Modules\Catalog\Requests\Admin\UpdateNamedCatalogItemRequest;
use App\Shared\Responses\Responser;
use Illuminate\Http\Request;

class ReaEstatUsageController extends Controller
{
    use Responser;

    public function index(Request $request)
    {
        $this->authorize('viewAny', ReaEstatUsage::class);

        try {
            $query = ReaEstatUsage::query();

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

            $usages = $query->latest()->paginate((int) $request->get('per_page', 20));

            return $this->apiResponse([
                'items' => $usages->items(),
                'pagination' => $this->paginate($usages),
            ], trans('api.success'));
        } catch (\Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function store(StoreNamedCatalogItemRequest $request)
    {
        $this->authorize('create', ReaEstatUsage::class);

        $usage = ReaEstatUsage::query()->create($request->validated());

        return $this->apiResponse($usage, trans('api.created_successfully'), 201);
    }

    public function show(int $id)
    {
        try {
            $usage = ReaEstatUsage::query()->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $this->authorize('view', $usage);

        return $this->apiResponse($usage, trans('api.success'));
    }

    public function update(UpdateNamedCatalogItemRequest $request, int $id)
    {
        try {
            $usage = ReaEstatUsage::query()->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $this->authorize('update', $usage);
        $usage->update($request->validated());

        return $this->apiResponse($usage->fresh(), trans('api.updated_successfully'));
    }

    public function destroy(int $id)
    {
        try {
            $usage = ReaEstatUsage::query()->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $this->authorize('delete', $usage);

        // لا يُحذف صف مرجعي مرتبط بطلبات عملاء (كان الحذف يُسلسِل حذف الطلبات). (CROSS-0)
        if (\App\Support\LookupUsage::contractsReference('rea_estat_usages', (int) $usage->id)) {
            return response()->json(['message' => trans('api.lookup_in_use'), 'code' => 422, 'success' => false], 422);
        }

        $usage->delete();

        return $this->apiResponse([], trans('api.deleted_successfully'));
    }
}
