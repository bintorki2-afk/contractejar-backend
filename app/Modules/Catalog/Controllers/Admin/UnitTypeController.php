<?php

namespace App\Modules\Catalog\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\UnitType;
use App\Modules\Catalog\Requests\Admin\SearchUnitTypeRequest;
use App\Modules\Catalog\Requests\Admin\StoreUnitTypeRequest;
use App\Modules\Catalog\Requests\Admin\UpdateUnitTypeRequest;
use App\Shared\Responses\Responser;
use Illuminate\Http\Request;

class UnitTypeController extends Controller
{
    use Responser;

    public function index(Request $request)
    {
        $this->authorize('viewAny', UnitType::class);

        try {
            $query = UnitType::query();

            if ($request->has('contract_type')) {
                $query->where('contract_type', $request->contract_type);
            }

            if ($request->has('rooms')) {
                $query->where('rooms', $request->rooms);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name_ar', 'like', "%{$search}%")
                      ->orWhere('name_en', 'like', "%{$search}%");
                });
            }

            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $unitTypes = $query->paginate($request->get('per_page', 20));

            return $this->apiResponse(
                [
                    'items' => $unitTypes->items(),
                    'pagination' => $this->paginate($unitTypes),
                ],
                trans('api.success')
            );
        } catch (\Exception $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function create()
    {
        $this->authorize('viewAny', UnitType::class);

        return $this->apiResponse(
            [
                'validation_rules' => [
                    'name_ar' => 'required|string|max:255',
                    'name_en' => 'nullable|string|max:255',
                    'contract_type' => 'required|in:housing,commercial',
                    'rooms' => 'nullable|in:Room,NoRoom',
                ],
                'contract_type_options' => [
                    ['value' => 'housing', 'label' => 'Housing'],
                    ['value' => 'commercial', 'label' => 'Commercial'],
                ],
                'rooms_options' => [
                    ['value' => 'Room', 'label' => 'Room'],
                    ['value' => 'NoRoom', 'label' => 'No Room'],
                ],
                'fields' => [
                    [
                        'name' => 'name_ar',
                        'label' => 'Arabic Name',
                        'type' => 'text',
                        'required' => true,
                        'max_length' => 255,
                    ],
                    [
                        'name' => 'name_en',
                        'label' => 'English Name',
                        'type' => 'text',
                        'required' => false,
                        'max_length' => 255,
                    ],
                    [
                        'name' => 'contract_type',
                        'label' => 'Contract Type',
                        'type' => 'select',
                        'required' => true,
                        'options' => [
                            ['value' => 'housing', 'label' => 'Housing'],
                            ['value' => 'commercial', 'label' => 'Commercial'],
                        ],
                    ],
                    [
                        'name' => 'rooms',
                        'label' => 'Rooms',
                        'type' => 'select',
                        'required' => false,
                        'options' => [
                            ['value' => 'Room', 'label' => 'Room'],
                            ['value' => 'NoRoom', 'label' => 'No Room'],
                        ],
                    ],
                ],
            ],
            trans('api.success')
        );
    }

    public function store(StoreUnitTypeRequest $request)
    {
        $this->authorize('create', UnitType::class);

        $unitType = UnitType::create($request->validated());

        return $this->apiResponse($unitType, trans('api.created_successfully'), 201);
    }

    public function show($id)
    {
        $unitType = UnitType::find($id);

        if (! $unitType) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $this->authorize('view', $unitType);

        return $this->apiResponse($unitType, trans('api.success'));
    }

    public function update(UpdateUnitTypeRequest $request, $id)
    {
        $unitType = UnitType::find($id);

        if (! $unitType) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $this->authorize('update', $unitType);
        $unitType->update($request->validated());

        return $this->apiResponse($unitType->fresh(), trans('api.updated_successfully'));
    }

    public function destroy($id)
    {
        $unitType = UnitType::find($id);

        if (! $unitType) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $this->authorize('delete', $unitType);

        // لا يُحذف صف مرجعي مرتبط بطلبات عملاء (كان الحذف يُسلسِل حذف الطلبات). (CROSS-0)
        if (\App\Support\LookupUsage::contractsReference('unit_types', (int) $unitType->id)) {
            return response()->json(['message' => trans('api.lookup_in_use'), 'code' => 422, 'success' => false], 422);
        }

        $unitType->delete();

        return $this->apiResponse([], trans('api.deleted_successfully'));
    }

    public function search(SearchUnitTypeRequest $request)
    {
        $this->authorize('viewAny', UnitType::class);

        try {
            $search = $request->search;
            $query = UnitType::query();

            $query->where(function ($q) use ($search) {
                $q->where('name_ar', 'like', "%{$search}%");
            });

            if ($request->has('contract_type')) {
                $query->where('contract_type', $request->contract_type);
            }

            if ($request->has('rooms')) {
                $query->where('rooms', $request->rooms);
            }

            $unitTypes = $query->latest()->paginate($request->get('per_page', 20));

            return $this->apiResponse(
                [
                    'items' => $unitTypes->items(),
                    'pagination' => $this->paginate($unitTypes),
                ],
                trans('api.success')
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (\Exception $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }
}
