<?php

namespace App\Services;

use App\Exceptions\NotarizationBlockedException;
use App\Models\Contract;
use App\Services\Payments\ContractPaymentState;
use App\Support\AuthenticatedEmployee;
use App\Support\ContractJourney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * قاعدة المنتج (دفعة هـ — E2/E5): لا يُنقل الطلب إلى «توثيق العقد في إيجار» أو «مكتمل»
 * إلا إذا كان مدفوعاً بالكامل (Moyasar أو حوالة بنكية) ولا توجد رسوم بانتظار الدفع.
 * الرد: 422 { code: payment_required | charge_pending }. مدير النظام يتجاوز بـ `force=1` (يُسجَّل).
 *
 * (حلّت محل قاعدة «المسودة قبل التوثيق» التي أُلغيت مع مرحلة إرسال المسودة.)
 */
class PaymentBeforeNotarizationRule
{
    public function __construct(private readonly ContractPaymentState $paymentState) {}

    /**
     * @return array{forced: bool, forced_by: int|null}
     *
     * @throws NotarizationBlockedException
     */
    public function assert(Request $request, Contract $contract, ?int $statusId, ?string $statusName): array
    {
        $none = ['forced' => false, 'forced_by' => null];

        if (! ContractJourney::isNotarizationStatus($statusId, $statusName)) {
            return $none;
        }

        return $this->assertCanNotarize($request, $contract);
    }

    /**
     * @return array{forced: bool, forced_by: int|null}
     *
     * @throws NotarizationBlockedException
     */
    public function assertCanNotarize(Request $request, Contract $contract): array
    {
        $state = $this->paymentState->state($contract);
        if ($state['can_notarize']) {
            return ['forced' => false, 'forced_by' => null];
        }

        if ($request->boolean('force')) {
            $employee = AuthenticatedEmployee::from($request);
            if ($employee !== null && $employee->isSystemAdmin()) {
                Log::warning('Payment-before-notarization rule overridden by system admin', [
                    'contract_id' => $contract->id,
                    'order_number' => $contract->uuid,
                    'reason' => $state['notarize_block_reason'],
                    'employee_id' => $employee->id,
                ]);

                return ['forced' => true, 'forced_by' => (int) $employee->id];
            }
        }

        throw NotarizationBlockedException::because(
            (string) $state['notarize_block_reason'],
            (string) $state['notarize_block_message'],
        );
    }
}
