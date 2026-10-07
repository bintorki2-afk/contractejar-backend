<?php

namespace App\Modules\Contracts\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Modules\Auth\Support\AuthMobile;
use App\Services\MoyasarPaymentService;
use App\Shared\Responses\Responser;
use App\Support\ContractFrontendStatus;
use Illuminate\Http\Request;

/**
 * تتبّع الطلب بدون حساب (الموقع): رقم الطلب + رقم الجوال.
 *
 * عام لكن مقيّد (throttle) ولا يُرجع إلا ملخص الحالة — لا بيانات هوية ولا مرفقات.
 * رقم الجوال يُطابق: رقم تواصل الضيف، رقم المستخدم، جوال المالك، جوال المستأجر.
 */
class ContractTrackController extends Controller
{
    use Responser;

    public function __construct(private readonly MoyasarPaymentService $payments) {}

    public function track(Request $request)
    {
        $data = $request->validate([
            'order' => ['required', 'string', 'max:64'],
            'mobile' => ['required', 'string', 'regex:/^(00966|966|0)?5\d{8}$/'],
        ]);

        $contract = $this->findByOrder(trim($data['order']));
        $variants = AuthMobile::lookupVariants($data['mobile']);

        if ($contract === null || ! $this->mobileMatches($contract, $variants)) {
            // رسالة واحدة للحالتين حتى لا يُستخدم المسار لتخمين أرقام الطلبات.
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $status = ContractFrontendStatus::for($contract);
        $timeline = ContractFrontendStatus::statusTimeline($contract);
        $paid = $this->payments->isPaymentConfirmed((string) $contract->uuid);
        $awaitingPayment = ! $paid && (int) $contract->step >= 7 && ! $contract->is_delete;

        return $this->apiResponse([
            'order_number' => (string) $contract->uuid,
            'id' => $contract->id,
            'uuid' => (string) $contract->uuid,
            'contract_type' => $contract->contract_type,
            'name_real_estate' => $contract->name_real_estate,
            'step' => (int) $contract->step,
            'is_draft' => (bool) $contract->is_draft,
            'is_paid' => $paid,
            'awaiting_payment' => $awaitingPayment,
            'payment_url' => $awaitingPayment ? route('v2.payment.show', ['uuid' => $contract->uuid]) : null,
            'status' => $status['status'],
            'status_label' => $status['status_label'],
            'status_color' => $status['status_color'] ?? null,
            'status_client_explanation' => $status['status_client_explanation'] ?? null,
            'timeline' => array_map(static fn (array $row) => [
                'status_label' => $row['status_label'] ?? '',
                'at' => $row['at'] ?? ($row['created_at'] ?? null),
            ], $timeline),
            'created_at' => optional($contract->created_at)->format('Y-m-d'),
            'updated_at' => optional($contract->updated_at)->format('Y-m-d H:i'),
        ], trans('api.success'));
    }

    private function findByOrder(string $order): ?Contract
    {
        $base = Contract::query()
            ->with(['contractStatus', 'draftContractStatus', 'receivedContract', 'statusHistories', 'user'])
            ->where('is_delete', 0);

        // رقم الطلب الظاهر للعميل هو `uuid` (6 أرقام)؛ نقبل أيضاً المعرّف الداخلي.
        if (preg_match('/^\d{1,12}$/', $order) === 1) {
            $digits = ltrim($order, '0');

            return (clone $base)->where('uuid', $order)->first()
                ?? (clone $base)->where('uuid', $digits)->first()
                ?? ($digits !== '' ? (clone $base)->whereKey((int) $digits)->first() : null);
        }

        return (clone $base)->where('uuid', $order)->first();
    }

    /** @param  list<string>  $variants */
    private function mobileMatches(Contract $contract, array $variants): bool
    {
        $candidates = array_filter([
            $contract->user?->contact_mobile,
            $contract->user?->mobile,
            $contract->property_owner_mobile,
            $contract->tenant_mobile,
            $contract->mobile_of_property_owner_agent,
        ]);

        foreach ($candidates as $candidate) {
            $candidateVariants = AuthMobile::lookupVariants((string) $candidate);
            if (array_intersect($candidateVariants, $variants) !== []) {
                return true;
            }
        }

        return false;
    }
}
