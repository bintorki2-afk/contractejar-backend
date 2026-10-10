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

        if ($contract === null) {
            // طلب خدمة «تغيير المؤجر» بنفس رقم الطلب + الجوال.
            $lessorChange = $this->findLessorChange(trim($data['order']), $variants);
            if ($lessorChange !== null) {
                return $this->apiResponse(array_merge($lessorChange->toClientArray(), [
                    'contract_type' => 'lessor_change',
                    'name_real_estate' => null,
                    'step' => null,
                    'is_draft' => false,
                    'status_client_explanation' => $lessorChange->status_note,
                    'timeline' => array_values(array_filter([
                        ['status_label' => 'تم إنشاء الطلب', 'at' => optional($lessorChange->created_at)->toDateTimeString()],
                        $lessorChange->paid_at ? ['status_label' => 'تم الدفع', 'at' => $lessorChange->paid_at->toDateTimeString()] : null,
                        $lessorChange->completed_at ? ['status_label' => 'اكتمل الطلب', 'at' => $lessorChange->completed_at->toDateTimeString()] : null,
                    ])),
                ]), trans('api.success'));
            }
        }

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
            'smart_link' => \App\Support\SmartLink::for($contract),
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
            // رحلة الطلب (دفعة هـ — E3): 3 خطوات مع done/current/at/by + الحالة الجانبية (ملغي/مسترجع).
            'journey' => ContractFrontendStatus::journey($contract),
            'journey_side_state' => \App\Support\ContractJourney::sideState($contract),
            'journey_sentence' => \App\Support\ContractJourney::RULE_SENTENCE,
            // دفعة (هـ) — 2.1/2.3/2.4: حالة الدفع + التفاصيل + الرسوم + طلبات المرفق الناقص.
            'payment_state' => $trackState = ($paymentState = app(\App\Services\Payments\ContractPaymentState::class))->state($contract),
            // QA-F W-30: is_paid = الدفعة الأصلية فقط؛ هذا الحقل يقول إن على العميل مبلغاً متبقياً (رسم معلّق/فرق).
            'has_outstanding' => (float) ($trackState['outstanding'] ?? 0) > 0.009,
            'outstanding' => (float) ($trackState['outstanding'] ?? 0),
            'payment_details' => $paymentState->details($contract),
            'charges' => app(\App\Services\Charges\ChargeService::class)->forCustomer($contract),
            'pending_data_requests' => app(\App\Services\DataRequests\ContractDataRequestService::class)->pendingForCustomer($contract),
            'activities' => app(\App\Services\Orders\ContractActivityLogger::class)->forCustomer($contract),
            'refund' => $refund = \App\Services\Payments\PaymentRefundService::summaryFor($contract),
            'refunded_amount' => $refund['amount'],
            'created_at' => optional($contract->created_at)->format('Y-m-d'),
            // QA-F W-11: «آخر تحديث» يتحرك مع الرسوم وطلبات المرفق الناقص والسجل (لا contracts.updated_at وحده).
            'updated_at' => optional($lastActivity = $this->lastActivityAt($contract))->format('Y-m-d H:i'),
            'last_activity_at' => optional($lastActivity)->toIso8601String(),
        ], trans('api.success'));
    }

    private function lastActivityAt(Contract $contract): ?\Illuminate\Support\Carbon
    {
        $candidates = [$contract->updated_at];
        $sources = [
            'contract_charges' => ['contract_id', ['created_at', 'updated_at']],
            'contract_data_requests' => ['contract_id', ['created_at', 'updated_at']],
            'contract_activities' => ['contract_id', ['created_at']],
        ];
        foreach ($sources as $table => [$fk, $columns]) {
            try {
                if (! \App\Support\SchemaCache::hasTable($table)) {
                    continue;
                }
                foreach ($columns as $column) {
                    $value = \Illuminate\Support\Facades\DB::table($table)->where($fk, $contract->id)->max($column);
                    if ($value !== null) {
                        $candidates[] = \Illuminate\Support\Carbon::parse($value);
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $latest = null;
        foreach ($candidates as $c) {
            if ($c !== null && ($latest === null || $c->greaterThan($latest))) {
                $latest = \Illuminate\Support\Carbon::parse($c);
            }
        }

        return $latest;
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
    private function findLessorChange(string $order, array $variants): ?\App\Models\LessorChangeRequest
    {
        $digits = ltrim(preg_replace('/\D+/', '', $order) ?? '', '0');
        if ($digits === '') {
            return null;
        }

        $row = \App\Models\LessorChangeRequest::findByUuid($digits);
        if ($row === null) {
            return null;
        }
        $row->loadMissing('user');

        $candidates = array_filter([$row->mobile, $row->user?->contact_mobile, $row->user?->mobile]);
        foreach ($candidates as $candidate) {
            if (array_intersect(AuthMobile::lookupVariants((string) $candidate), $variants) !== []) {
                return $row;
            }
        }

        return null;
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
