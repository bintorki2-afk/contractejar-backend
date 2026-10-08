<?php

namespace App\Services;

use App\Models\Contract;
use App\Support\AuthenticatedEmployee;
use App\Support\ContractJourney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * قاعدة المنتج (ف2) — مُنفَّذة على الخادم:
 * لا يُنقل الطلب إلى «توثيق العقد في إيجار» أو «مكتمل» إلا إذا سبق إرسال المسودة للعميل
 * عبر واتساب (حالة «إرسال مسودة العقد لكم عبر واتساب» في سجل الحالات).
 *
 * مدير النظام يستطيع التجاوز صراحةً بـ `force=1` (يُسجَّل في اللوق وفي سجل الحالة).
 */
class DraftBeforeNotarizationRule
{
    /**
     * @return array{forced: bool, forced_by: int|null}  معلومات للتسجيل في سجل الحالة
     *
     * @throws ValidationException
     */
    public function assert(Request $request, Contract $contract, ?int $statusId, ?string $statusName): array
    {
        $none = ['forced' => false, 'forced_by' => null];

        if (! ContractJourney::isNotarizationStatus($statusId, $statusName)) {
            return $none;
        }

        if (ContractJourney::draftWasSent($contract)) {
            return $none;
        }

        if ($request->boolean('force')) {
            $employee = AuthenticatedEmployee::from($request);

            if ($employee !== null && $employee->isSystemAdmin()) {
                Log::warning('Draft-before-notarization rule overridden by super admin', [
                    'contract_id' => $contract->id,
                    'order_number' => $contract->uuid,
                    'target_status_id' => $statusId,
                    'target_status' => $statusName,
                    'employee_id' => $employee->id,
                ]);

                return ['forced' => true, 'forced_by' => (int) $employee->id];
            }
        }

        throw ValidationException::withMessages([
            'contract_status_id' => [ContractJourney::RULE_MESSAGE],
        ]);
    }
}
