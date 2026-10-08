<?php

namespace App\Modules\Catalog\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Region;
use App\Modules\Catalog\Requests\Admin\StoreRegionRequest;
use App\Modules\Catalog\Requests\Admin\UpdateRegionRequest;
use App\Shared\Responses\Responser;
use Illuminate\Http\Request;

class RegionController extends Controller
{
    use Responser;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Region::class);

        try {
            $query = Region::query()->withCount('city');

            if ($request->filled('search')) {
                $search = $request->string('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%");
                });
            }

            $perPage = $this->perPageFromRequest($request);
            $regions = $query->latest()->paginate($perPage);

            return $this->apiResponse(
                [
                    'items' => $regions->items(),
                    'pagination' => $this->paginate($regions),
                ],
                trans('api.success')
            );
        } catch (\Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function store(StoreRegionRequest $request)
    {
        $this->authorize('create', Region::class);

        try {
            $region = Region::query()->create($request->validated());

            return $this->apiResponse($region, trans('api.created_successfully'), 201);
        } catch (\Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function show(int $id)
    {
        try {
            $region = Region::query()
                ->with('city')
                ->withCount('city')
                ->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $this->authorize('view', $region);

        return $this->apiResponse($region, trans('api.success'));
    }

    public function update(UpdateRegionRequest $request, int $id)
    {
        try {
            $region = Region::query()->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $this->authorize('update', $region);
        $region->update($request->validated());

        return $this->apiResponse($region->fresh(), trans('api.updated_successfully'));
    }

    public function destroy(int $id)
    {
        try {
            $region = Region::query()->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $this->authorize('delete', $region);

        // لا يُحذف صف مرجعي مرتبط بطلبات عملاء (كان الحذف يُسلسِل حذف الطلبات). (CROSS-0)
        if (\App\Support\LookupUsage::contractsReference('regions', (int) $region->id)) {
            return response()->json(['message' => trans('api.lookup_in_use'), 'code' => 422, 'success' => false], 422);
        }

        $region->delete();

        return $this->apiResponse([], trans('api.deleted_successfully'));
    }
}
