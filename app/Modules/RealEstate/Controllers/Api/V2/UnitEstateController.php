<?php

namespace App\Modules\RealEstate\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\Unit\StoreUnitsRequest;
use App\Http\Requests\Api\V2\Unit\UpdateUnitRequest;
use App\Http\Resources\Api\V2\UnitResource;
use App\Http\Traits\Responser;
use App\Models\RealEstate;
use App\Models\UnitsReal;
use App\Services\RealEstateUnitsService;
use App\Support\TypeFurnished;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class UnitEstateController extends Controller
{
    use Responser;

    private function unitEagerLoads(): array
    {
        return ['unitType', 'unitUsage', 'realEstate'];
    }

    public function index($id)
    {
        $userReal = RealEstate::where('user_id', Auth::id())->findOrFail($id);
        $user = Auth::user();
        $units = UnitsReal::where('real_estates_units_id', $userReal->id)
            ->where('user_id', $user->id)
            ->with($this->unitEagerLoads())
            ->get();

        return $this->apiResponse(UnitResource::collection($units), trans('api.units'));
    }

    public function all($id)
    {
        $user = Auth::user();

        try {
            $userReal = RealEstate::where('user_id', Auth::id())->findOrFail($id);
            $units = UnitsReal::where('real_estates_units_id', $userReal->id)
                ->where('user_id', $user->id)
                ->with($this->unitEagerLoads())
                ->get();

            return $this->apiResponse(UnitResource::collection($units), trans('api.units'), 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorMessage(trans('لا يوجد عقار'), 404);
        } catch (\Exception $e) {
            return $this->errorMessage(trans('حدث خطأ ما'), 500);
        }
    }

    public function show($id)
    {
        try {
            $user = auth()->user();
            $userUnit = UnitsReal::where('id', $id)
                ->where('user_id', $user->id)
                ->with($this->unitEagerLoads())
                ->firstOrFail();

            return $this->apiResponse(new UnitResource($userUnit), trans('تفاصيل الوحده'), 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorMessage(trans('لا يوجد وحده'), 404);
        } catch (\Exception $e) {
            return $this->errorMessage(trans('حدث خطأ ما'), 500);
        }
    }

    public function create(StoreUnitsRequest $request)
    {
        $user = auth()->user();
        $userId = (int) $user->id;

        $realEstate = RealEstate::query()
            ->where('user_id', $userId)
            ->find((int) $request->real_estates_units_id);

        if (! $realEstate) {
            return $this->errorMessage(trans('لا يوجد عقار'), 404);
        }

        try {
            $units = app(RealEstateUnitsService::class)->attachToRealEstate(
                $realEstate,
                $request->input('units'),
                $userId
            );
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        }

        $allUnits = UnitsReal::query()
            ->where('real_estates_units_id', $realEstate->id)
            ->where('user_id', $userId)
            ->with($this->unitEagerLoads())
            ->get();

        return $this->apiResponse([
            'created' => UnitResource::collection(collect($units)),
            'created_count' => count($units),
            'units' => UnitResource::collection($allUnits),
            'units_count' => $allUnits->count(),
        ], trans('api.created_success'), 201);
    }

    public function update(UpdateUnitRequest $request, $id)
    {
        $units = UnitsReal::where('user_id', auth()->id())->findOrFail($id);

        $data = $request->only([
            'unit_type_id',
            'unit_usage_id',
            'contract_type',
            'unit_number',
            'floor_number',
            'unit_area',
            'tootal_rooms',
            'The_number_of_halls',
            'The_number_of_kitchens',
            'The_number_of_toilets',
            'window_ac',
            'split_ac',
            'electricity_meter_number',
            'water_meter_number',
            'real_estates_units_id',
            'kitchen_tank',
            'furnished',
            'type_furnished',
            'electricity_meter',
            'water_meter',
            'electricity_meter_ownership',
            'water_meter_ownership',
        ]);

        $data['user_id'] = auth()->id();

        if (! empty($data['real_estates_units_id'])) {
            $ownedRealEstate = RealEstate::query()
                ->where('user_id', auth()->id())
                ->whereKey($data['real_estates_units_id'])
                ->exists();

            if (! $ownedRealEstate) {
                return $this->errorMessage(trans('لا يوجد عقار'), 404);
            }
        }

        foreach (['kitchen_tank', 'furnished', 'electricity_meter', 'water_meter'] as $flag) {
            if ($request->exists($flag)) {
                $data[$flag] = (int) $request->boolean($flag);
            }
        }

        foreach (['electricity_meter_ownership', 'water_meter_ownership'] as $ownership) {
            if ($request->exists($ownership)) {
                $value = $request->input($ownership);
                $data[$ownership] = ($value === '' || $value === null) ? null : $value;
            }
        }

        if ($request->exists('type_furnished')) {
            $data['type_furnished'] = TypeFurnished::normalize($request->input('type_furnished'));
        }

        try {
            $units->update(UnitsReal::attributesForApi($data));
            return $this->apiResponse(new UnitResource($units->fresh($this->unitEagerLoads())), trans('api.success'), 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorMessage(trans('api.not_have_unit'), 404);
        } catch (\Exception $e) {
            return $this->errorMessage(trans('api.error'), 500);
        }
    }

    public function delete($id)
    {
        $realEstate = UnitsReal::where('user_id', auth()->id())->findOrFail($id);
        // QA-F C1: وحدة مرتبطة بطلب لا تُحذف — حذفها كان يمحو وحدات الطلبات المدفوعة.
        if ($realEstate->contracts()->exists() || $realEstate->linkedContracts()->exists()) {
            return $this->errorMessage(trans('api.unit_has_contracts'), 422);
        }
        $property = $realEstate->realEstate;
        $countBefore = $property ? $property->units()->count() : 0;
        $realEstate->delete();
        // QA-F PROPS-13: عدّاد وحدات العقار يتبع الحذف.
        $property?->syncUnitsCountAfterRemoval($countBefore);
        return $this->successMessage(trans('api.success'), 200);
    }
}

