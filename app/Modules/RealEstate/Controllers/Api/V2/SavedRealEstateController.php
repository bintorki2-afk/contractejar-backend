<?php

namespace App\Modules\RealEstate\Controllers\Api\V2;

use App\Http\Resources\Api\V2\RealEstate\RealEstateFromContractResource;
use App\Models\Contract;
use App\Models\RealEstate;
use App\Models\UnitsReal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SavedRealEstateController extends \App\Http\Controllers\Api\SavedRealEstateController
{
    public function SavedRealEstate(Request $request)
    {
        $userId = Auth::id();

        $validated = $request->validate([
            'contract_id' => 'required|integer',
            'name_real_estate' => 'required|string',
        ]);

        $contract = Contract::findOwnedOrFail($validated['contract_id']);

        // عقد أُنشئ من عقار محفوظ أصلاً: لا شيء يُحفظ (الخيار لا يُعرض للعميل).
        $state = \App\Support\SavedPropertyState::forContract($contract);
        if ($state['from_saved']) {
            return response()->json([
                'message' => 'هذا العقد مرتبط بعقار محفوظ مسبقاً',
                'code' => Response::HTTP_OK,
                'success' => true,
                'data' => ['saved_property' => $state],
            ]);
        }

        // مُفعّل سابقاً لنفس العقد: نُعيد العقار نفسه بدل إنشاء نسخة مكررة (idempotent).
        $existing = RealEstate::query()
            ->where('source_contract_id', $contract->id)
            ->where('user_id', $userId)
            ->first();
        if ($existing) {
            if ($existing->name_real_estate !== $validated['name_real_estate']) {
                $existing->update(['name_real_estate' => $validated['name_real_estate']]);
            }

            return response()->json([
                'message' => 'العقار محفوظ مسبقاً',
                'code' => Response::HTTP_OK,
                'success' => true,
                'data' => [
                    'real_estate' => $existing->fresh(),
                    'saved_property' => \App\Support\SavedPropertyState::forContract($contract->fresh()),
                ],
            ]);
        }

        DB::beginTransaction();

        try {
            $real = RealEstate::create(
                (new RealEstateFromContractResource($contract, $userId, $validated['name_real_estate']))->payload()
            );

            $linkedUnits = $contract->units()->get();
            $primaryUnit = null;

            if ($linkedUnits->isNotEmpty()) {
                foreach ($linkedUnits as $linkedUnit) {
                    $linkedUnit->update([
                        'real_estates_units_id' => $real->id,
                    ]);
                }
                $primaryUnit = $linkedUnits->first();
            } else {
                $primaryUnit = UnitsReal::create(UnitsReal::attributesForApi(
                    $this->unitPayloadFromContract($contract, $real->id, $userId)
                ));

                \App\Models\ContractUnit::query()->create([
                    'contract_id' => $contract->id,
                    'real_unit_id' => $primaryUnit->id,
                    'real_estate_id' => $real->id,
                ]);
            }

            $contract->update([
                'real_id' => $real->id,
                'real_units_id' => $primaryUnit?->id,
                'is_real' => true,
            ]);

            // Refresh pivot real_estate_id
            \App\Models\ContractUnit::query()
                ->where('contract_id', $contract->id)
                ->update(['real_estate_id' => $real->id]);

            DB::commit();

            return response()->json([
                'message' => 'تمت إضافة العقار والوحدة بنجاح',
                'code' => Response::HTTP_CREATED,
                'success' => true,
                'data' => [
                    'real_estate' => $real->fresh(),
                    'units_real' => $primaryUnit?->fresh(),
                    'units' => $contract->units()->with(['unitType', 'unitUsage'])->get(),
                    'contract_v2_fields' => [
                        'image_instrument_from_the_front' => $contract->image_instrument_from_the_front,
                        'image_instrument_from_the_back' => $contract->image_instrument_from_the_back,
                        'Image_from_the_agency' => $contract->Image_from_the_agency,
                        'copy_power_of_attorney_from_heirs_to_agent' => $contract->copy_power_of_attorney_from_heirs_to_agent,
                        'Image_inheritance_certificate' => $contract->Image_inheritance_certificate,
                        'tenant_roles' => $contract->tenant_roles,
                        'tenant_role_ids' => $contract->tenant_role_ids ?? [],
                        'tenant_role_id' => $contract->tenant_role_id,
                        'additional_terms' => $contract->additional_terms,
                        'text_additional_terms' => $contract->text_additional_terms,
                    ],
                ],
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'حدث خطأ أثناء إضافة العقار',
                'code' => Response::HTTP_INTERNAL_SERVER_ERROR,
                'success' => false,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * إلغاء حفظ العقار الذي أُنشئ من هذا العقد (قبل الدفع أو بعده): يُحذف العقار ووحداته
     * التي أُنشئت من العقد فقط، ويُفك الربط من العقد.
     *
     * DELETE /api/v2/save/property/{contract}
     */
    public function unsave(int $contractId)
    {
        $userId = Auth::id();
        $contract = Contract::findOwnedOrFail($contractId);

        $state = \App\Support\SavedPropertyState::forContract($contract);
        if ($state['from_saved']) {
            return response()->json([
                'message' => 'هذا العقد مرتبط بعقار محفوظ مسبقاً ولا يمكن فك ارتباطه من هنا',
                'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'success' => false,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $real = RealEstate::query()
            ->where('source_contract_id', $contract->id)
            ->where('user_id', $userId)
            ->first();

        DB::beginTransaction();
        try {
            if ($real) {
                // العقود الأخرى التي استخدمت هذا العقار لاحقاً تبقى سليمة (لا حذف إذا كان مستخدماً).
                $usedElsewhere = Contract::query()
                    ->where('real_id', $real->id)
                    ->where('id', '!=', $contract->id)
                    ->exists();

                if (! $usedElsewhere) {
                    UnitsReal::query()
                        ->where('real_estates_units_id', $real->id)
                        ->update(['real_estates_units_id' => null]);
                    $real->delete();
                }
            }

            $contract->update(['real_id' => null, 'is_real' => false]);
            \App\Models\ContractUnit::query()
                ->where('contract_id', $contract->id)
                ->update(['real_estate_id' => null]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'حدث خطأ أثناء إلغاء حفظ العقار',
                'code' => Response::HTTP_INTERNAL_SERVER_ERROR,
                'success' => false,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'message' => 'تم إلغاء حفظ العقار',
            'code' => Response::HTTP_OK,
            'success' => true,
            'data' => ['saved_property' => \App\Support\SavedPropertyState::forContract($contract->fresh())],
        ]);
    }

    /**
     * Copy contract step-5 unit fields into a new real_units row.
     *
     * @return array<string, mixed>
     */
    private function unitPayloadFromContract(Contract $contract, int $realEstateId, int $userId): array
    {
        $attrs = $contract->getAttributes();

        return [
            'real_estates_units_id' => $realEstateId,
            'user_id' => $userId,
            'contract_type' => $contract->contract_type,
            'unit_number' => $contract->unit_number,
            'unit_type_id' => $contract->unit_type_id,
            'unit_usage_id' => $contract->unit_usage_id,
            'floor_number' => $contract->floor_number,
            'unit_area' => $contract->unit_area,
            'tootal_rooms' => $contract->tootal_rooms,
            'The_number_of_halls' => $contract->The_number_of_halls,
            'The_number_of_kitchens' => $contract->The_number_of_kitchens,
            'The_number_of_toilets' => $contract->The_number_of_toilets
                ?? ($attrs['The_number_of_the_toilet'] ?? null),
            'window_ac' => $contract->window_ac,
            'split_ac' => $contract->split_ac,
            'electricity_meter_number' => $contract->electricity_meter_number,
            'water_meter_number' => $contract->water_meter_number,
            'kitchen_tank' => (bool) $contract->kitchen_tank,
            'furnished' => (bool) $contract->furnished,
            'type_furnished' => \App\Support\TypeFurnished::normalize($contract->type_furnished),
            'electricity_meter' => (bool) $contract->electricity_meter,
            'water_meter' => (bool) $contract->water_meter,
            'electricity_meter_ownership' => $contract->electricity_meter_ownership,
            'water_meter_ownership' => $contract->water_meter_ownership,
            'Number_parking_spaces' => $attrs['Number_parking_spaces'] ?? null,
        ];
    }
}
