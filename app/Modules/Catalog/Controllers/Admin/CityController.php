<?php

namespace App\Modules\Catalog\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\City;
use App\Modules\Catalog\Requests\Admin\StoreCityRequest;
use App\Modules\Catalog\Requests\Admin\UpdateCityRequest;
use App\Shared\Responses\Responser;
use Illuminate\Http\Request;

class CityController extends Controller
{
    use Responser;

    public function index(Request $request)
    {
        $this->authorize('viewAny', City::class);

        try {
            $query = City::query()->with('regions');

            if ($request->filled('region_id')) {
                $query->where('region_id', (int) $request->region_id);
            }

            if ($request->filled('search')) {
                $search = $request->string('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%");
                });
            }

            $perPage = $this->perPageFromRequest($request);
            $cities = $query->latest()->paginate($perPage);

            return $this->apiResponse(
                [
                    'items' => $cities->items(),
                    'pagination' => $this->paginate($cities),
                ],
                trans('api.success')
            );
        } catch (\Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function store(StoreCityRequest $request)
    {
        $this->authorize('create', City::class);

        try {
            $city = City::query()->create($request->validated());
            $city->load('regions');

            return $this->apiResponse($city, trans('api.created_successfully'), 201);
        } catch (\Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function show(int $id)
    {
        try {
            $city = City::query()->with('regions')->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $this->authorize('view', $city);

        return $this->apiResponse($city, trans('api.success'));
    }

    public function update(UpdateCityRequest $request, int $id)
    {
        try {
            $city = City::query()->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $this->authorize('update', $city);
        $city->update($request->validated());
        $city->load('regions');

        return $this->apiResponse($city->fresh(), trans('api.updated_successfully'));
    }

    public function destroy(int $id)
    {
        try {
            $city = City::query()->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $this->authorize('delete', $city);

        // لا يُحذف صف مرجعي مرتبط بطلبات عملاء (كان الحذف يُسلسِل حذف الطلبات). (CROSS-0)
        if (\App\Support\LookupUsage::contractsReference('cities', (int) $city->id)) {
            return response()->json(['message' => trans('api.lookup_in_use'), 'code' => 422, 'success' => false], 422);
        }

        $city->delete();

        return $this->apiResponse([], trans('api.deleted_successfully'));
    }
}
