<?php

namespace App\Modules\RealEstate\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitsResource;
use App\Http\Traits\Responser;
use App\Models\RealEstate;
use App\Models\UnitsReal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UnitEstateController extends Controller
{
    use Responser;


    public function index($id)
    {
        $userReal = RealEstate::where('user_id', Auth::id())->findOrFail($id);
        $user=Auth::user();
         $units = UnitsReal::where('real_estates_units_id', $userReal->id)->where('user_id',$user->id)->get();
    
         return $this->apiResponse(UnitsResource::collection($units), trans('api.units'));

    }
    public function all($id)
    {
        $user = Auth::user();
    
        try {
            // Fetch the real estate based on the provided ID
            $userReal = RealEstate::where('user_id', $user->id)->findOrFail($id);
    
            // Fetch the units associated with the real estate and the authenticated user
            $units = UnitsReal::where('real_estates_units_id', $userReal->id)
                              ->where('user_id', $user->id)
                              ->get();
    
            // Map over the units and prepare the response data
            $response = $units->map(function ($unit) {
                return [
                    'id' => $unit->id,
                    'unit_number' => $unit->unit_number,
                    'real_estates_units_id' => $unit->real_estates_units_id,   
                    'unit_type_id' => $unit->unitType->name_trans,   
                    'unit_type_name' => $unit->unit_type_id,   
                    'unit_usage_name' => $unit->unitUsage->name_trans,  
                    'floor_number' => $unit->floor_number,   
                    'unit_area' => $unit->unit_area,   
                    'total_rooms' => $unit->tootal_rooms,   
                    'the_number_of_halls' => $unit->The_number_of_halls,   
                    'the_number_of_kitchens' => $unit->The_number_of_kitchens,  
                    'The_number_of_toilets' => $unit->The_number_of_toilets,  
                    'window_ac' => $unit->window_ac,   
                    'split_ac' => $unit->split_ac,   
                    'electricity_meter_number' => $unit->electricity_meter_number,  
                    'water_meter_number' => $unit->water_meter_number,  
                ];
            });
    
            // Return the successful response with the units data
            return $this->apiResponse($response, trans('api.units'), 200);
        } catch (ModelNotFoundException $e) {
            // Return error message if the real estate was not found
            return $this->errorMessage(trans('لا يوجد عقار'), 404);
        } catch (\Exception $e) {
            // Return a generic error message for other exceptions
            return $this->errorMessage(trans('حدث خطأ ما'), 500);  
        }
    }
    

    public function show($id)
    {
        try {
            $user = auth()->user();
            
            $userUnit = UnitsReal::where('id', $id)
                                 ->where('user_id', $user->id)
                                 ->firstOrFail();
    
          
            return $this->apiResponse(new UnitsResource($userUnit), trans('تفاصيل الوحده'), 200);
        } catch (ModelNotFoundException $e) {
        
            return $this->errorMessage(trans('لا يوجد وحده'), 404);
        } catch (\Exception $e) {
            
            return $this->errorMessage(trans('حدث خطأ ما'), 500);  
        }
    }
    
    
    

    public function create(Request $request)
    {
        $rules = [
            'real_estates_units_id' => 'required|exists:real_estates,id',
            'unit_type_id' => 'required|exists:unit_types,id',
            'unit_usage_id' => 'required|exists:unit_usage,id',
            'contract_type' => 'nullable|in:housing,commercial',
            'unit_number' => 'required|string',
            'floor_number' => 'required|integer',
            'unit_area' => 'required|numeric',
            'tootal_rooms' => 'nullable|integer',
            'The_number_of_halls' => 'nullable|integer',
            'The_number_of_kitchens' => 'nullable|integer',
            'The_number_of_toilets' => 'nullable|integer',
            'window_ac' => 'nullable|integer',
            'split_ac' => 'required|integer',
            'electricity_meter_number' => 'nullable|string|max:255',
            'water_meter_number' => 'nullable|string|max:255',
        ];
    
        $messages = [
            'real_estates_units_id.required' => 'حقل العقار مطلوب.',
            'real_estates_units_id.exists' => 'العقار المحدد غير موجود.',
            'unit_type_id.required' => 'حقل نوع الوحدة مطلوب.',
            'unit_type_id.exists' => 'نوع الوحدة المحدد غير موجود.',
            'unit_usage_id.required' => 'حقل استخدام الوحدة مطلوب.',
            'unit_usage_id.exists' => 'استخدام الوحدة المحدد غير موجود.',
            'unit_number.required' => 'رقم الوحدة مطلوب.',
            'floor_number.required' => 'رقم الطابق مطلوب.',
            'floor_number.max' => 'يجب ألا يتجاوز رقم الطابق 15.',
            'unit_area.required' => 'مساحة الوحدة مطلوبة.',
            'tootal_rooms.required' => 'حقل عدد الغرف مطلوب.',
            'The_number_of_halls.required' => 'عدد الصالات مطلوب.',
            'The_number_of_kitchens.required' => 'عدد المطابخ مطلوب.',
            'The_number_of_toilets.required' => 'عدد الحمامات مطلوب.',
            'split_ac.required' => 'عدد مكيفات السبليت مطلوب.',
        ];
    
        $this->validate($request, $rules, $messages);
    
        $user = auth()->user();

        $realEstate = RealEstate::query()
            ->where('user_id', $user->id)
            ->find($request->real_estates_units_id);

        if (! $realEstate) {
            return $this->errorMessage(trans('api.not_have_real'), 404);
        }

        $data = [
            'real_estates_units_id' => $request->real_estates_units_id,
            'unit_type_id' => $request->unit_type_id,
            'unit_usage_id' => $request->unit_usage_id,
            'contract_type' => $request->input('contract_type'),
            'unit_number' => $request->unit_number,
            'floor_number' => $request->floor_number,
            'unit_area' => $request->unit_area,
            'tootal_rooms' => $request->tootal_rooms,
            'The_number_of_halls' => $request->The_number_of_halls,
            'The_number_of_kitchens' => $request->The_number_of_kitchens,
            'The_number_of_toilets' => $request->The_number_of_toilets ,
            'window_ac' => $request->window_ac,
            'split_ac' => $request->split_ac,
            'electricity_meter_number' => $request->electricity_meter_number,
            'water_meter_number' => $request->water_meter_number,
            'user_id' => $user->id,
        ];
    
        $realEstateUnit = UnitsReal::create(UnitsReal::attributesForApi($data));
    
        return response()->json([
            'message' => trans('api.created_success'),
            'code' => 201,
            'success' => true,
            'data' => $realEstateUnit
        ]);

     }
    
    

    public function update(Request $request, $id)
    {
        $rules = [
            'real_estates_units_id' => 'sometimes|exists:real_estates,id',
            'unit_type_id' => 'sometimes|exists:unit_types,id',
            'unit_usage_id' => 'sometimes|exists:unit_usage,id',
            'contract_type' => 'nullable|sometimes|in:housing,commercial',
            'unit_number' => 'sometimes|string|max:255',
            'floor_number' => 'sometimes|integer|max:15',
            'unit_area' => 'sometimes|numeric',
            'tootal_rooms' => 'sometimes|integer|max:10',
            'The_number_of_halls' => 'sometimes|integer|max:10',
            'The_number_of_kitchens' => 'sometimes|integer|max:10',
            'The_number_of_toilets' => 'sometimes|integer|max:10',
            'window_ac' => 'sometimes|max:10',
            'split_ac' => 'sometimes|max:10',
            'electricity_meter_number' => 'nullable|string|max:255',
            'water_meter_number' => 'nullable|string|max:255',
        ];
    
        $this->validate($request, $rules);
    
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
        ]);
    
        if (! empty($data['real_estates_units_id'])) {
            $ownedRealEstate = RealEstate::query()
                ->where('user_id', auth()->id())
                ->whereKey($data['real_estates_units_id'])
                ->exists();

            if (! $ownedRealEstate) {
                return $this->errorMessage(trans('api.not_have_real'), 404);
            }
        }

        $data['user_id'] = auth()->id();
    
        try {
            $units->update(UnitsReal::attributesForApi($data));
            return $this->apiResponse( $data,trans('api.success'), 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorMessage(trans('api.not_have_unit'), 404);
        } catch (\Exception $e) {
            return $this->errorMessage(trans('api.error'), ['error' => $e->getMessage()], 500);
        }
    }
    

    public function delete($id){

        $realEstate = UnitsReal::where('user_id', auth()->id())->findOrFail($id);
        // QA-F C1: وحدة مرتبطة بطلب لا تُحذف — حذفها كان يمحو وحدات الطلبات المدفوعة.
        if ($realEstate->contracts()->exists() || $realEstate->linkedContracts()->exists()) {
            return $this->errorMessage(trans('api.unit_has_contracts'), 422);
        }
        $realEstate->delete();
        return $this->successMessage( trans('api.success'), 200);
    }

      
}