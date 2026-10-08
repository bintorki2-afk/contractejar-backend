<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\RealEstate;

/**
 * حالة «حفظ بيانات العقار» لعقد:
 *  - from_saved: العقد أُنشئ من عقار محفوظ أصلاً → الخيار لا يُعرض.
 *  - saved: تم حفظ عقار من هذا العقد (مرة واحدة فقط).
 */
final class SavedPropertyState
{
    /** @return array{show_option: bool, from_saved: bool, saved: bool, real_estate_id: int|null} */
    public static function forContract(Contract $contract): array
    {
        $realId = $contract->real_id ? (int) $contract->real_id : null;
        $fromSaved = false;
        $saved = false;

        if ($realId) {
            try {
                $source = RealEstate::query()->whereKey($realId)->value('source_contract_id');
                $fromSaved = $source === null || (int) $source !== (int) $contract->id;
                $saved = ! $fromSaved;
            } catch (\Throwable) {
                $fromSaved = (bool) $contract->is_real;
            }
        }

        if (! $saved) {
            try {
                $saved = RealEstate::query()->where('source_contract_id', $contract->id)->exists();
            } catch (\Throwable) {
                $saved = false;
            }
        }

        return [
            'show_option' => ! $fromSaved,
            'from_saved' => $fromSaved,
            'saved' => $saved,
            'real_estate_id' => $realId,
        ];
    }
}
