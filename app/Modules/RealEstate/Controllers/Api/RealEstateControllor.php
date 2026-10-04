<?php

namespace App\Modules\RealEstate\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Traits\Responser;
use App\Models\City;
use App\Models\RealEstate;
use App\Modules\RealEstate\Requests\Api\Step1Request;
use App\Modules\RealEstate\Requests\Api\Step2Request;
use App\Modules\RealEstate\Requests\Api\Step3Request;
use App\Modules\RealEstate\Requests\Api\UpdateStep1Request;
use App\Modules\RealEstate\Requests\Api\UpdateStep2Request;
use App\Modules\RealEstate\Requests\Api\UpdateStep3Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;


class RealEstateControllor extends Controller
{
    use Responser;
 
    public function index()
    {
        $user = auth()->user();
        $data = RealEstate::where('user_id', $user->id)->get();
    
        $response = $data->map(function ($realEstate) {  
            return [
                'id' => $realEstate->id,
                'name' => $realEstate->name_real_estate,
                'name_owner' => $realEstate->name_owner,
                'contract_type' => $realEstate->contract_type,
                'number_of_units_in_realestate'=>$realEstate->number_of_units_in_realestate,
                'property_place_name' => $realEstate->tenantEntityRegion->name_trans ?? null, 
                'property_city_name' => $realEstate->tenantEntityCity->name_trans ?? null,  
            ];
        });
    
        return $this->apiResponse($data, trans('api.real_estate'));
    }
    


    public function all()
    {
        $user = auth()->user();
        $realEstates = RealEstate::where('user_id', $user->id)->get();
    
        if(!$realEstates)
        {
             return $this->errorMessage(trans('api.not_have_real'), 404);
        }
        $response = $realEstates->map(function ($realEstate) {
            return [
                'id' => $realEstate->id,
                'name_real_estate' => $realEstate->name_real_estate,
                'contract_type' => $realEstate->contract_type,
                'name_owner' => $realEstate->name_owner,
                'property_city_name' => $realEstate->tenantEntityCity->name_trans ?? null,  
                'property_place_name' => $realEstate->tenantEntityRegion->name_trans ?? null,  
                'number_of_units_in_realestate'=>$realEstate->number_of_units_in_realestate,
                'Number_of_units_already_existence' => (string) $realEstate->units()->count(),
              ];
        });
        return $this->apiResponse($response, trans('api.real_estate'));
    }
    

     //return realestat
     public function show($id)
     {
         try {
             $user = auth()->user();
             $realEstate = RealEstate::where('user_id', $user->id)->findOrFail($id);
     
             $realEstateData = [
                 'id'=>$realEstate->id,
                 'add_legal_agent_of_owner' => $realEstate->add_legal_agent_of_owner,
                 'id_num_of_property_owner_agent' => $realEstate->id_num_of_property_owner_agent,
                 'dob_of_property_owner_agent' => $realEstate->dob_of_property_owner_agent,
                 'mobile_of_property_owner_agent' => $realEstate->mobile_of_property_owner_agent,
                 'agency_number_in_instrument_of_property_owner' => $realEstate->agency_number_in_instrument_of_property_owner,
                 'agency_instrument_date_of_property_owner' => $realEstate->agency_instrument_date_of_property_owner,
                 'property_owner_is_deceased' => $realEstate->property_owner_is_deceased,
                 'contract_ownership' => $realEstate->contract_ownership,
                 'instrument_type' => $realEstate->instrument_type,
                 'contract_type' => $realEstate->contract_type,
                 'date_first_registration' => $realEstate->date_first_registration,
                 'real_estate_registry_number' => $realEstate->real_estate_registry_number,
                 'property_owner_dob_hijri' => $realEstate->property_owner_dob_hijri,
                 'instrument_number' => $realEstate->instrument_number,
                 'instrument_history' => $realEstate->instrument_history,
                 'name_owner' => $realEstate->name_owner,
                 'property_owner_id_num' => $realEstate->property_owner_id_num,
                 'number_of_units_in_realestate' => $realEstate->number_of_units_in_realestate,
                 'property_owner_mobile'=>$realEstate->property_owner_mobile,
                 'property_owner_iban'=>$realEstate->property_owner_iban,
                 'name_real_estate' => $realEstate->name_real_estate,
                 'number_of_floors' => $realEstate->number_of_floors,
                 'property_type_id' => $realEstate->property_type_id,
                 'property_usages_id' => $realEstate->property_usages_id,
                 'property_type_name' => $realEstate->propertyType->name_ar,
                 'property_usages_name' => $realEstate->propertyUsages->name_ar,
                 'type_real_estate_other' => $realEstate->type_real_estate_other,
                 'property_city_id' => $realEstate->property_city_id,
                 'property_city_name' => $realEstate->tenantEntityCity->name_ar,  
                 'property_place_id' => $realEstate->property_place_id,
                 'property_place_name' => $realEstate->tenantEntityRegion->name_ar,  
                 'street' => $realEstate->street,
                 'neighborhood' => $realEstate->neighborhood,
                 'building_number' => $realEstate->building_number,
                 'postal_code' => $realEstate->postal_code,
                 'extra_figure' => $realEstate->extra_figure,
                 'user_id' => $realEstate->user_id,
                 'step' => $realEstate->step,
                 'is_deleted' => $realEstate->is_deleted,
             ];
     
             return $this->apiResponse($realEstateData, trans('api.real_estate'), 200);
         } catch (ModelNotFoundException $e) {
             return $this->errorMessage(trans('api.not_have_real'), 404);
         } catch (\Exception $e) {
             return $this->errorMessage(trans('api.error_occurred'), 500);
         }
     }
     
    public function showUnits($id)
    {
        try {
            $user = auth()->user();
            $realEstate = RealEstate::with('units')->where('user_id', $user->id)->findOrFail($id);

            // Same payload shape as before, but sensitive legal documents are exposed
            // as temporary signed URLs (served from the private disk), never as raw
            // public /storage paths — keeps the add/edit prefill able to display them.
            $data = $realEstate->toArray();
            foreach (\App\Support\RealEstateImage::FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = \App\Support\RealEstateImage::signedUrl($realEstate, $field);
                }
            }

            return $this->apiResponse($data, trans('api.have_real'), 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorMessage(trans('api.not_have_real'));
        }
    }
    public function step1(Step1Request $request)
    {
        $user=Auth::user();

        $realEstate = RealEstate::create([
            'user_id'=>$user->id,
            'contract_ownership' => $request->contract_ownership,
            'contract_type'=>$request->contract_type ,
            'instrument_number' => $request->instrument_number,
            'instrument_history' => $request->instrument_history,
            'real_estate_registry_number' => $request->real_estate_registry_number,
            'date_first_registration' => $request->date_first_registration,
            'property_owner_is_deceased' => $request->property_owner_is_deceased,
            'number_of_units_in_realestate' => $request->number_of_units_in_realestate,
            'instrument_type' => $request->instrument_type,
            'property_type_id' => $request->property_type_id,
            'property_usages_id' => $request->property_usages_id,
            'number_of_floors' => $request->number_of_floors,
            'step' => 2  
        ]);

        return response()->json([
            'message' => trans('api.created_success'),
            'code' => 200,
            'success' => true,
            'data' => $realEstate
        ]);
    }

    public function step2(Step2Request $request)
    {
        $user=Auth::user();

        // Get existing RealEstate record
        $realEstate = RealEstate::query()
            ->where('user_id', $user->id)
            ->find($request->id);

        if (!$realEstate) {
            return response()->json([
                'message' => trans('api.real_estate_not_found'),
                'code' => 404,
                'success' => false
            ]);
        }

        // Check if city exists in the selected region
        $city = City::where('id', $request->property_city_id)
                    ->where('region_id', $request->property_place_id)
                    ->first();

        if (!$city) {
            return $this->errorMessage(trans('api.city_not_include_region'));
        }

        // Update the real estate with new data
        $realEstate->update([
            'property_place_id' => $request->property_place_id,
            'property_city_id' => $request->property_city_id,
            'neighborhood' => $request->neighborhood,
            'street' => $request->street,
            'building_number' => $request->building_number,
            'postal_code' => $request->postal_code,
            'extra_figure' => $request->extra_figure,
            'step' => 3 // Update step to 3
        ]);

        return response()->json([
            'message' => trans('api.updated_success'),
            'code' => 200,
            'success' => true,
            'data' => $realEstate
        ]);
    }

    public function step3(Step3Request $request)
    {
        $user=Auth::user();

        // Get existing RealEstate record
        $realEstate = RealEstate::query()
            ->where('user_id', $user->id)
            ->find($request->id);

        if (!$realEstate) {
            return response()->json([
                'message' => trans('api.real_estate_not_found'),
                'code' => 404,
                'success' => false
            ]);
        }

        // Update the real estate with new data
        $realEstate->update([
            'name_owner' => $request->name_owner,
            'add_legal_agent_of_owner'=>$request->add_legal_agent_of_owner,
            'name_real_estate'=> $request->name_real_estate,
            'property_owner_id_num' => $request->property_owner_id_num,
            'property_owner_dob_hijri' => $request->property_owner_dob_hijri,
            'property_owner_mobile' => $request->property_owner_mobile,
            'property_owner_iban' => $request->property_owner_iban,
            'id_num_of_property_owner_agent' => $request->id_num_of_property_owner_agent,
            'dob_of_property_owner_agent' => $request->dob_of_property_owner_agent,
            'mobile_of_property_owner_agent' => $request->mobile_of_property_owner_agent,
            'agency_number_in_instrument_of_property_owner' => $request->agency_number_in_instrument_of_property_owner,
            'agency_instrument_date_of_property_owner' => $request->agency_instrument_date_of_property_owner,
            'step' => 4  
        ]);

        $realEstateData = [
            'id'=>$realEstate->id,

            'add_legal_agent_of_owner' => $realEstate->add_legal_agent_of_owner,
            'id_num_of_property_owner_agent' => $realEstate->id_num_of_property_owner_agent,
            'dob_of_property_owner_agent' => $realEstate->dob_of_property_owner_agent,
            'mobile_of_property_owner_agent' => $realEstate->mobile_of_property_owner_agent,
            'agency_number_in_instrument_of_property_owner' => $realEstate->agency_number_in_instrument_of_property_owner,
            'agency_instrument_date_of_property_owner' => $realEstate->agency_instrument_date_of_property_owner,
            'property_owner_is_deceased' => $realEstate->property_owner_is_deceased,
            'contract_ownership' => $realEstate->contract_ownership,
            'instrument_type' => $realEstate->instrument_type,
        
            'date_first_registration' => $realEstate->date_first_registration,
            'real_estate_registry_number' => $realEstate->real_estate_registry_number,
            'property_owner_dob_hijri' => $realEstate->property_owner_dob_hijri,
            'instrument_number' => $realEstate->instrument_number,
            'instrument_history' => $realEstate->instrument_history,
            'name_owner' => $realEstate->name_owner,
            'property_owner_id_num' => $realEstate->property_owner_id_num,
            'number_of_units_in_realestate' => $realEstate->number_of_units_in_realestate,
            'property_owner_mobile' => $request->property_owner_mobile,
            'property_owner_iban' => $request->property_owner_iban,
            'name_real_estate' => $realEstate->name_real_estate,
            'number_of_floors' => $realEstate->number_of_floors,
            'property_type_id' => $realEstate->property_type_id,
            'property_usages_id' => $realEstate->property_usages_id,
            'property_type_name' => $realEstate->propertyType->name_ar,
            'property_usages_name' => $realEstate->propertyUsages->name_ar,
            'type_real_estate_other' => $realEstate->type_real_estate_other,
            'property_city_id' => $realEstate->property_city_id,
            'property_city_name' => $realEstate->tenantEntityCity->name_ar,  
            'property_place_id' => $realEstate->property_place_id,
            'property_place_name' => $realEstate->tenantEntityRegion->name_ar,  
            'street' => $realEstate->street,
            'neighborhood' => $realEstate->neighborhood,
            'building_number' => $realEstate->building_number,
            'postal_code' => $realEstate->postal_code,
            'extra_figure' => $realEstate->extra_figure,
            'user_id' => $realEstate->user_id,
            'step' => $realEstate->step,
            'is_deleted' => $realEstate->is_deleted,
        ];

        return response()->json([
            'message' => trans('api.updated_success'),
            'code' => 200,
            'success' => true,
            'data' => $realEstateData
        ]);
    }

        public function updateStep1(UpdateStep1Request $request){

        $realEstate = RealEstate::where('user_id', Auth::id())->findOrFail($request->id); 

        if ($realEstate->is_completed) {
            return $this->errorMessage(trans('api.completed_contract'));
        }

        $data = $request->only([
            'contract_ownership', 'instrument_number', 'instrument_history', 
            'real_estate_registry_number', 'date_first_registration', 
            'property_owner_is_deceased', 'number_of_units_in_realestate', 'instrument_type', 
            'property_type_id', 'property_usages_id', 'number_of_floors'
        ]);

        if ($request->instrument_type == 'electronic') {
            $data['instrument_number'] = $request->instrument_number;
            $data['instrument_history'] = date('Y-m-d', strtotime($request->instrument_history));
        } elseif ($request->instrument_type == 'strong_argument') {
            $data['real_estate_registry_number'] = $request->real_estate_registry_number;
            $data['date_first_registration'] = $request->date_first_registration;
        }

        $data['step'] = 2;
        $realEstate->update($data);

        $realEstateData = [
            'id'=>$realEstate->id,

            'add_legal_agent_of_owner' => $realEstate->add_legal_agent_of_owner,
            'id_num_of_property_owner_agent' => $realEstate->id_num_of_property_owner_agent,
            'dob_of_property_owner_agent' => $realEstate->dob_of_property_owner_agent,
            'mobile_of_property_owner_agent' => $realEstate->mobile_of_property_owner_agent,
            'agency_number_in_instrument_of_property_owner' => $realEstate->agency_number_in_instrument_of_property_owner,
            'agency_instrument_date_of_property_owner' => $realEstate->agency_instrument_date_of_property_owner,
            'property_owner_is_deceased' => $realEstate->property_owner_is_deceased,
            'contract_ownership' => $realEstate->contract_ownership,
            'instrument_type' => $realEstate->instrument_type,
            'contract_type' => $realEstate->contract_type,
            'date_first_registration' => $realEstate->date_first_registration,
            'real_estate_registry_number' => $realEstate->real_estate_registry_number,
            'property_owner_dob_hijri' => $realEstate->property_owner_dob_hijri,
            'instrument_number' => $realEstate->instrument_number,
            'instrument_history' => $realEstate->instrument_history,
            'name_owner' => $realEstate->name_owner,
            'property_owner_id_num' => $realEstate->property_owner_id_num,
            'number_of_units_in_realestate' => $realEstate->number_of_units_in_realestate,
            'property_owner_mobile'=>$realEstate->property_owner_mobile,
            'property_owner_iban'=>$realEstate->property_owner_iban,
            
            'name_real_estate' => $realEstate->name_real_estate,
            'number_of_floors' => $realEstate->number_of_floors,
            'property_type_id' => $realEstate->property_type_id,
            'property_usages_id' => $realEstate->property_usages_id,
            'property_type_name' => $realEstate->propertyType->name_ar,
            'property_usages_name' => $realEstate->propertyUsages->name_ar,
            'type_real_estate_other' => $realEstate->type_real_estate_other,
            'property_city_id' => $realEstate->property_city_id,
            'property_city_name' => $realEstate->tenantEntityCity->name_ar,  
            'property_place_id' => $realEstate->property_place_id,
            'property_place_name' => $realEstate->tenantEntityRegion->name_ar,  
            'street' => $realEstate->street,
            'neighborhood' => $realEstate->neighborhood,
            'building_number' => $realEstate->building_number,
            'postal_code' => $realEstate->postal_code,
            'extra_figure' => $realEstate->extra_figure,
            'user_id' => $realEstate->user_id,
            'step' => $realEstate->step,
            'is_deleted' => $realEstate->is_deleted,
        ];


        return response()->json([
            'message' => trans('api.updated_success'),
            'code' => 200,
            'success' => true,
            'data' => $realEstateData
        ]);
    }

    public function updateStep2(UpdateStep2Request $request){

        $realEstate = RealEstate::where('user_id', Auth::id())->findOrFail($request->id); 

        $city = City::where('id', $request->property_city_id)
                    ->where('region_id', $request->property_place_id)
                    ->first();

        if (!$city) {
            return $this->errorMessage(trans('api.city_not_include_region'));
        }

        $data = $request->only([
            'property_place_id', 'property_city_id', 'neighborhood', 
            'street', 'building_number', 'postal_code', 'extra_figure'
        ]);

        $data['step'] = 3;
        $realEstate->update($data);

        $realEstateData = [
            'id'=>$realEstate->id,

            'add_legal_agent_of_owner' => $realEstate->add_legal_agent_of_owner,
            'id_num_of_property_owner_agent' => $realEstate->id_num_of_property_owner_agent,
            'dob_of_property_owner_agent' => $realEstate->dob_of_property_owner_agent,
            'mobile_of_property_owner_agent' => $realEstate->mobile_of_property_owner_agent,
            'agency_number_in_instrument_of_property_owner' => $realEstate->agency_number_in_instrument_of_property_owner,
            'agency_instrument_date_of_property_owner' => $realEstate->agency_instrument_date_of_property_owner,
            'property_owner_is_deceased' => $realEstate->property_owner_is_deceased,
            'contract_ownership' => $realEstate->contract_ownership,
            'instrument_type' => $realEstate->instrument_type,
            'contract_type' => $realEstate->contract_type,
            'date_first_registration' => $realEstate->date_first_registration,
            'real_estate_registry_number' => $realEstate->real_estate_registry_number,
            'property_owner_dob_hijri' => $realEstate->property_owner_dob_hijri,
            'instrument_number' => $realEstate->instrument_number,
            'instrument_history' => $realEstate->instrument_history,
            'name_owner' => $realEstate->name_owner,
            'property_owner_id_num' => $realEstate->property_owner_id_num,
            'number_of_units_in_realestate' => $realEstate->number_of_units_in_realestate,
            'property_owner_mobile' => $request->property_owner_mobile,
            'property_owner_iban' => $request->property_owner_iban,
            'name_real_estate' => $realEstate->name_real_estate,
            'number_of_floors' => $realEstate->number_of_floors,
            'property_type_id' => $realEstate->property_type_id,
            'property_usages_id' => $realEstate->property_usages_id,
            'property_type_name' => $realEstate->propertyType->name_ar,
            'property_usages_name' => $realEstate->propertyUsages->name_ar,
            'type_real_estate_other' => $realEstate->type_real_estate_other,
            'property_city_id' => $realEstate->property_city_id,
            'property_city_name' => $realEstate->tenantEntityCity->name_ar,  
            'property_place_id' => $realEstate->property_place_id,
            'property_place_name' => $realEstate->tenantEntityRegion->name_ar,  
            'street' => $realEstate->street,
            'neighborhood' => $realEstate->neighborhood,
            'building_number' => $realEstate->building_number,
            'postal_code' => $realEstate->postal_code,
            'extra_figure' => $realEstate->extra_figure,
            'user_id' => $realEstate->user_id,
            'step' => $realEstate->step,
            'is_deleted' => $realEstate->is_deleted,
        ];


        return response()->json([
            'message' => trans('api.updated_success'),
            'code' => 200,
            'success' => true,
            'data' => $realEstateData
        ]);
    }

    public function updateStep3(UpdateStep3Request $request)
    {

        $realEstate = RealEstate::where('user_id', Auth::id())->findOrFail($request->id);  

        $data = $request->only([
            'name_real_estate','add_legal_agent_of_owner',
            'name_owner', 'property_owner_id_num', 'property_owner_dob_hijri', 
            'property_owner_mobile', 'property_owner_iban', 
            'id_num_of_property_owner_agent', 'dob_of_property_owner_agent', 
            'mobile_of_property_owner_agent', 'agency_number_in_instrument_of_property_owner', 
            'agency_instrument_date_of_property_owner'
        ]);

        $data['step'] = 4;
        $realEstate->update($data);

        $realEstateData = [
            'id'=>$realEstate->id,
            'add_legal_agent_of_owner' => $realEstate->add_legal_agent_of_owner,
            'id_num_of_property_owner_agent' => $realEstate->id_num_of_property_owner_agent,
            'dob_of_property_owner_agent' => $realEstate->dob_of_property_owner_agent,
            'mobile_of_property_owner_agent' => $realEstate->mobile_of_property_owner_agent,
            'agency_number_in_instrument_of_property_owner' => $realEstate->agency_number_in_instrument_of_property_owner,
            'agency_instrument_date_of_property_owner' => $realEstate->agency_instrument_date_of_property_owner,
            'property_owner_is_deceased' => $realEstate->property_owner_is_deceased,
            'contract_ownership' => $realEstate->contract_ownership,
            'instrument_type' => $realEstate->instrument_type,
            'contract_type' => $realEstate->contract_type,
            'date_first_registration' => $realEstate->date_first_registration,
            'real_estate_registry_number' => $realEstate->real_estate_registry_number,
            'property_owner_dob_hijri' => $realEstate->property_owner_dob_hijri,
            'instrument_number' => $realEstate->instrument_number,
            'instrument_history' => $realEstate->instrument_history,
            'name_owner' => $realEstate->name_owner,
            'property_owner_id_num' => $realEstate->property_owner_id_num,
            'number_of_units_in_realestate' => $realEstate->number_of_units_in_realestate,
            'property_owner_mobile' => $request->property_owner_mobile,
            'property_owner_iban' => $request->property_owner_iban,
            'name_real_estate' => $realEstate->name_real_estate,
            'number_of_floors' => $realEstate->number_of_floors,
            'property_type_id' => $realEstate->property_type_id,
            'property_usages_id' => $realEstate->property_usages_id,
            'property_type_name' => $realEstate->propertyType->name_ar,
            'property_usages_name' => $realEstate->propertyUsages->name_ar,
            'type_real_estate_other' => $realEstate->type_real_estate_other,
            'property_city_id' => $realEstate->property_city_id,
            'property_city_name' => $realEstate->tenantEntityCity->name_ar,  
            'property_place_id' => $realEstate->property_place_id,
            'property_place_name' => $realEstate->tenantEntityRegion->name_ar,  
            'street' => $realEstate->street,
            'neighborhood' => $realEstate->neighborhood,
            'building_number' => $realEstate->building_number,
            'postal_code' => $realEstate->postal_code,
            'extra_figure' => $realEstate->extra_figure,
            'user_id' => $realEstate->user_id,
            'step' => $realEstate->step,
            'is_deleted' => $realEstate->is_deleted,
        ];

        return response()->json([
            'message' => trans('api.updated_success'),
            'code' => 200,
            'success' => true,
            'data' => $realEstateData
        ]);
    }



        public function delete($id){
        try {
            $realEstate = RealEstate::with('units')->where('user_id', Auth::id())->findOrFail($id);

            if ($realEstate->units->isNotEmpty()) {
                foreach ($realEstate->units as $unit) {
                    $unit->delete();
                }
            }

            $realEstate->delete();

            return $this->successMessage(trans('api.success'), 200);
        } catch (ModelNotFoundException $e) {
            return $this->successMessage(trans('api.not_found'), 404);
        }
        }
        



    
    }