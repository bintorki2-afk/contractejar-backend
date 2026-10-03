<?php

namespace App\Modules\RealEstate\Controllers\Api\V2;

use App\Http\Controllers\Api\RealEstateControllor as ApiRealEstateControllor;
use App\Http\Requests\Api\V2\RealEstate\Step1RealEstateRequest;
use App\Http\Requests\Api\V2\RealEstate\UpdateStep1RealEstateRequest;
use App\Http\Requests\Api\V2\RealEstate\Step2RealEstateRequest;
use App\Http\Requests\Api\V2\RealEstate\Step3RealEstateRequest;
use App\Http\Resources\Api\V2\RealEstate\RealEstateResource;
use App\Http\Resources\Api\V2\RealEstate\Step1RealEstateResource;
use App\Http\Resources\Api\V2\RealEstate\Step2RealEstateResource;
use App\Http\Resources\Api\V2\RealEstate\Step3RealEstateResource;
use App\Models\RealEstate;
use App\Services\RealEstateUnitsService;
use App\Support\DateInputNormalizer;
use App\Support\HijriDobParts;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class RealEstateControllor extends ApiRealEstateControllor
{
    /**
     * @return array<int, string>
     */
    protected function realEstateEagerLoads(): array
    {
        return [
            'propertyType',
            'propertyUsages',
            'tenantEntityCity',
            'tenantEntityRegion',
            'units.unitType',
            'units.unitUsage',
        ];
    }

    protected function toStep1RealEstateRequest(Request $request): Step1RealEstateRequest
    {
        $form = Step1RealEstateRequest::createFrom($request);
        $form->setContainer(app())->setRedirector(app(Redirector::class));
        $form->validateResolved();

        return $form;
    }

    protected function toStep2RealEstateRequest(Request $request): Step2RealEstateRequest
    {
        $form = Step2RealEstateRequest::createFrom($request);
        $form->setContainer(app())->setRedirector(app(Redirector::class));
        $form->validateResolved();

        return $form;
    }

    protected function toStep3RealEstateRequest(Request $request): Step3RealEstateRequest
    {
        $form = Step3RealEstateRequest::createFrom($request);
        $form->setContainer(app())->setRedirector(app(Redirector::class));
        $form->validateResolved();

        return $form;
    }

    public function index()
    {
        $user = auth()->user();
        $data = RealEstate::query()
            ->where('user_id', $user->id)
            ->with($this->realEstateEagerLoads())
            ->get();
        return $this->apiResponse(RealEstateResource::collection($data), trans('api.real_estate'));
    }

    public function all()
    {
        $user = auth()->user();
        $realEstates = RealEstate::query()
            ->where('user_id', $user->id)
            ->with($this->realEstateEagerLoads())
            ->get();

        if (! $realEstates) {
            return $this->errorMessage(trans('api.not_have_real'), 404);
        }

        return $this->apiResponse(RealEstateResource::collection($realEstates), trans('api.real_estate'));
    }

    public function show($id)
    {
        $user = auth()->user();
        $realEstate = RealEstate::query()
            ->where('user_id', $user->id)
            ->with($this->realEstateEagerLoads())
            ->findOrFail($id);
        return $this->apiResponse(new RealEstateResource($realEstate), trans('api.real_estate'), 200);
    }

    public function step1(Request $request)
    {
        $request = $this->toStep1RealEstateRequest($request);
        $realEstate = RealEstate::create($request->attributesForCreate(Auth::id()));
        return response()->json([
            'message' => trans('api.success'),
            'code' => 200,
            'success' => true,
            'data' => new Step1RealEstateResource($realEstate->fresh($this->realEstateEagerLoads())),
        ]);
    }

    public function step2(Request $request)
    {
        return $this->saveOwnerStep($request, false);
    }

    /** Step 3: one or more units (same shape as contract step5). */
    public function step3(Request $request)
    {
        return $this->saveUnitsStep($request, false);
    }

    /** @return \Illuminate\Http\JsonResponse */
    protected function saveUnitsStep(Request $request, bool $isUpdate)
    {
        $form = $this->toStep3RealEstateRequest($request);
        $user = Auth::user();

        $realEstate = RealEstate::query()
            ->where('user_id', $user->id)
            ->findOrFail($form->integer('id'));

        $unitPayloads = $form->input('units', []);
        if (! is_array($unitPayloads)) {
            $unitPayloads = [];
        }

        try {
            $units = $unitPayloads === []
                ? $realEstate->units()->with(['unitType', 'unitUsage'])->get()->all()
                : app(RealEstateUnitsService::class)->syncForRealEstate(
                    $realEstate,
                    $unitPayloads,
                    (int) $user->id
                );
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        }

        $realEstate->update(['step' => 3]);

        $realEstate = $realEstate->fresh([
            ...$this->realEstateEagerLoads(),
        ]);

        return response()->json([
            'message' => $isUpdate ? trans('api.updated_success') : trans('api.success'),
            'code' => 200,
            'success' => true,
            'data' => new Step3RealEstateResource($realEstate),
            'units_count' => count($units),
        ]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    protected function saveOwnerStep(Request $request, bool $isUpdate)
    {
        $form = $this->toStep2RealEstateRequest($request);

        $user = Auth::user();
        $realEstate = RealEstate::where('user_id', $user->id)->findOrFail($form->integer('id'));

        $data = [
            'name_real_estate' => $form->input('name_real_estate'),
            'name_owner' => $form->name_owner,
            'property_owner_id_num' => $form->property_owner_id_num,
            'user_id' => $user->id,
            'type_dob_property_owner' => $form->input('type_dob_property_owner', 'hijri'),
            'property_owner_dob_hijri' => HijriDobParts::combine(
                $form->property_owner_dob_day,
                $form->property_owner_dob_month,
                $form->property_owner_dob_year
            ),
            'property_owner_mobile' => $form->property_owner_mobile,
            'property_owner_iban' => $form->property_owner_iban,
            'add_legal_agent_of_owner' => $form->add_legal_agent_of_owner,
            'step' => 2,
        ];

        $hasAgent = in_array((string) $form->add_legal_agent_of_owner, ['1', 'true'], true)
            || $form->add_legal_agent_of_owner === 1
            || $form->add_legal_agent_of_owner === true;

        if ($hasAgent) {
            $data['id_num_of_property_owner_agent'] = $form->id_num_of_property_owner_agent;
            $data['type_dob_property_owner_agent'] = $form->input('type_dob_property_owner_agent', 'hijri');
            $data['dob_of_property_owner_agent'] = HijriDobParts::combine(
                $form->input('dob_of_property_owner_agent_day'),
                $form->input('dob_of_property_owner_agent_month'),
                $form->input('dob_of_property_owner_agent_year')
            );
            $data['mobile_of_property_owner_agent'] = $form->mobile_of_property_owner_agent;
            $data['agency_number_in_instrument_of_property_owner'] = $form->agency_number_in_instrument_of_property_owner;
            $data['type_agency_instrument_date_of_property_owner'] = $form->input(
                'type_agency_instrument_date_of_property_owner',
                'hijri'
            );
            $data['agency_instrument_date_of_property_owner'] = DateInputNormalizer::combineFromParts(
                $form->input('agency_instrument_date_of_property_owner_day'),
                $form->input('agency_instrument_date_of_property_owner_month'),
                $form->input('agency_instrument_date_of_property_owner_year')
            );
            if ($request->hasFile('copy_of_the_authorization_or_agency')) {
                // Private disk + signed URL (see App\Support\RealEstateImage) — never world-readable.
                $data['copy_of_the_authorization_or_agency'] = $request->file('copy_of_the_authorization_or_agency')
                    ->store('authorizations', \App\Support\RealEstateImage::DISK);
            } elseif (! $isUpdate) {
                $data['copy_of_the_authorization_or_agency'] = $realEstate->copy_of_the_authorization_or_agency;
            }
        } else {
            $data['id_num_of_property_owner_agent'] = null;
            $data['type_dob_property_owner_agent'] = null;
            $data['dob_of_property_owner_agent'] = null;
            $data['mobile_of_property_owner_agent'] = null;
            $data['agency_number_in_instrument_of_property_owner'] = null;
            $data['agency_instrument_date_of_property_owner'] = null;
            $data['type_agency_instrument_date_of_property_owner'] = null;
            $data['copy_of_the_authorization_or_agency'] = null;
        }

        $realEstate->update($data);

        return response()->json([
            'message' => $isUpdate ? trans('api.updated_success') : trans('api.success'),
            'code' => 200,
            'success' => true,
            'data' => new Step2RealEstateResource($realEstate->fresh($this->realEstateEagerLoads())),
        ]);
    }

    public function updateStep1(Request $request)
    {
        $form = UpdateStep1RealEstateRequest::createFrom($request);
        $form->setContainer(app())->setRedirector(app(Redirector::class));
        $form->validateResolved();

        $user = Auth::user();
        $realEstate = RealEstate::where('user_id', $user->id)->findOrFail($form->input('id'));

        $realEstate->update($form->attributesForUpdate());

        return response()->json([
            'message' => trans('api.updated_success'),
            'code' => 200,
            'success' => true,
            'data' => new Step1RealEstateResource($realEstate->fresh($this->realEstateEagerLoads())),
        ]);
    }

    public function updateStep2(Request $request)
    {
        return $this->saveOwnerStep($request, true);
    }

    public function updateStep3(Request $request)
    {
        return $this->saveUnitsStep($request, true);
    }

    public function delete($id)
    {
        try {
            $user = Auth::user();
            $realEstate = RealEstate::with('units')
                ->where('user_id', $user->id)
                ->findOrFail($id);

            if ($realEstate->units->isNotEmpty()) {
                foreach ($realEstate->units as $unit) {
                    $unit->delete();
                }
            }

            $realEstate->delete();

            return $this->successMessage(trans('api.success'), 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }
    }
}
