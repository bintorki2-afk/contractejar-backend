<?php

namespace App\Modules\Contracts\Actions\Api;

use App\Models\Contract;
use Illuminate\Http\Request;

class SubmitContractStep5Action
{
    public function execute(Contract $contract, Request $request): Contract
    {
        if ($contract->is_completed) {
            abort(422, trans('api.completed_contract'));
        }

        $data = [
            'step' => 6,
            'unit_type_id' => $request->unit_type_id,
            'unit_usage_id' => $request->unit_usage_id,
            'unit_number' => $request->unit_number,
            'floor_number' => $request->floor_number,
            'unit_area' => $request->unit_area,
            'tootal_rooms' => $request->tootal_rooms,
            'The_number_of_halls' => $request->The_number_of_halls,
            'The_number_of_kitchens' => $request->The_number_of_kitchens,
            'The_number_of_toilets' => $request->The_number_of_toilets,
            'window_ac' => $request->window_ac,
            'split_ac' => $request->split_ac,
            'electricity_meter_number' => $request->electricity_meter_number,
            'water_meter_number' => $request->water_meter_number,
            // متابعة دفعة (د) — QA: القناة من العميل الحقيقي (لا تُفرض «app»).
            'app_or_web' => \App\Support\ClientChannel::fromRequest($request),
        ];

        $contract->update($data);

        return $contract;
    }
}
