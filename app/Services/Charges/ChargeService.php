<?php

namespace App\Services\Charges;

use App\Models\Contract;
use App\Models\ContractCharge;
use App\Models\Employee;
use App\Models\EmployeeNotification;
use App\Models\Payment;
use App\Services\CustomerNotificationService;
use App\Services\FirebaseNotificationService;
use App\Services\MessageTemplateService;
use App\Services\MoyasarPaymentService;
use App\Services\Orders\OrderFlowService;
use App\Services\Orders\OrderStageService;
use App\Services\Payments\ContractPaymentState;
use App\Support\ContractPricing;
use App\Support\SchemaCache;
use App\Support\SmartLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * الرسوم بعد الدفع (دفعة هـ — E5):
 *  - فرق السعر: بعد أي تعديل إداري يغيّر ContractPricing يُعاد الحساب — المستحق > المدفوع ⇒ رسم
 *    `price_difference` معلّق واحد (يستبدل السابق) بسبب تلقائي؛ المستحق < المدفوع ⇒ refund_due في payment_state.
 *  - الرسوم الإضافية: مبلغ حر + رسالة حرة يراها العميل كما هي (صلاحية payments.add_fee).
 *  - رابط Moyasar لمبلغ الرسم فقط (مفتاح الفاتورة chg-{uuid}-{id} + metadata.charge_id)، وتسوية عبر الـ webhook.
 */
class ChargeService
{
    public function __construct(
        private readonly OrderFlowService $flow,
        private readonly MessageTemplateService $templates,
        private readonly CustomerNotificationService $customers,
        private readonly ContractPaymentState $paymentState,
        private readonly OrderStageService $stages,
    ) {}

    /**
     * إعادة حساب فرق السعر بعد تعديل إداري. يرجع الرسم المعلّق (أو null) ومبلغ الاسترجاع المستحق.
     *
     * @param  array<string, array{label?: string, before?: mixed, after?: mixed}>|array<string, mixed>  $changed
     * @return array{charge: ContractCharge|null, difference: float, refund_due: float, reason: string|null}
     */
    public function syncPriceDifference(Contract $contract, ?Employee $employee = null, array $changed = []): array
    {
        $none = ['charge' => null, 'difference' => 0.0, 'refund_due' => 0.0, 'reason' => null];
        if (! SchemaCache::hasTable('contract_charges') || ! (bool) $contract->is_completed) {
            return $none;
        }

        $contract->refresh();
        $state = $this->paymentState->state($contract);
        if ($state['paid_total'] <= 0.009) {
            return $none;
        }

        // المستحق الأصلي الحي مقابل ما دُفع للأصل (المدفوع − مدفوع الرسوم الإضافية) بعد الاسترجاع.
        $extraPaid = round((float) $this->paymentState->successfulPayments($contract)
            ->filter(fn (Payment $p) => ! empty($p->charge_id) && ($p->kind ?? '') === Payment::KIND_EXTRA_FEE)->sum('amount'), 2);
        $paidForOriginal = round($state['paid_total'] - $extraPaid - $state['refunded_total'], 2);
        $difference = round($state['original_due'] - $paidForOriginal, 2);

        $existing = ContractCharge::query()->where('contract_id', $contract->id)
            ->where('kind', ContractCharge::KIND_PRICE_DIFFERENCE)->where('status', ContractCharge::STATUS_PENDING)->first();

        if ($difference > 0.009) {
            $reason = $this->autoReason($contract, $changed);
            if ($existing !== null && abs((float) $existing->amount - $difference) < 0.01) {
                if ($reason !== null && $existing->message !== $reason) {
                    $existing->forceFill(['message' => $reason, 'internal_reason' => $reason])->save();
                }

                return ['charge' => $existing, 'difference' => $difference, 'refund_due' => 0.0, 'reason' => $existing->message];
            }
            if ($existing !== null) {
                $existing->forceFill(['status' => ContractCharge::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $employee?->id])->save();
            }
            $reason ??= 'فرق سعر بعد تعديل بيانات الطلب';
            $charge = ContractCharge::query()->create([
                'contract_id' => $contract->id,
                'kind' => ContractCharge::KIND_PRICE_DIFFERENCE,
                'amount' => $difference,
                'message' => $reason,
                'internal_reason' => $reason,
                'status' => ContractCharge::STATUS_PENDING,
                'created_by' => $employee?->id,
            ]);
            $charge->forceFill(['payment_key' => Payment::chargeKey((string) $contract->uuid, (int) $charge->id)])->save();

            $this->flow->activity($contract, 'charge_created', $employee, null, [
                'charge_id' => $charge->id, 'kind' => $charge->kind, 'amount' => $difference, 'message' => $reason,
            ], $employee ? 'employee' : 'system', 'فرق سعر +'.$this->money($difference).' ر.س — '.$reason);

            try {
                $this->customers->chargePaymentRequested($contract, $charge, $this->customerPayUrl($contract, $charge));
            } catch (\Throwable $e) {
                Log::warning('price difference notification failed', ['charge_id' => $charge->id, 'error' => $e->getMessage()]);
            }

            return ['charge' => $charge, 'difference' => $difference, 'refund_due' => 0.0, 'reason' => $reason];
        }

        // لا فرق أو فرق لصالح العميل ⇒ أي فرق معلّق سابق يُلغى.
        if ($existing !== null) {
            $existing->forceFill(['status' => ContractCharge::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $employee?->id])->save();
            $this->flow->activity($contract, 'charge_cancelled', $employee, null, ['charge_id' => $existing->id, 'kind' => $existing->kind, 'amount' => (float) $existing->amount], $employee ? 'employee' : 'system', 'أُلغي فرق السعر المعلّق بعد التعديل');
        }

        $refundDue = $difference < -0.009 ? round(abs($difference), 2) : 0.0;
        if ($refundDue > 0) {
            $this->flow->activity($contract, 'refund_due', $employee, null, ['amount' => $refundDue], $employee ? 'employee' : 'system', 'فرق لصالح العميل '.$this->money($refundDue).' ر.س — يمكن استرجاعه بضغطة واحدة');
        }

        return ['charge' => null, 'difference' => $difference, 'refund_due' => $refundDue, 'reason' => null];
    }

    /**
     * رسوم إضافية بمبلغ ورسالة حرّين (صلاحية payments.add_fee).
     *
     * @throws ValidationException
     */
    public function addExtraFee(Contract $contract, float $amount, string $message, ?Employee $employee, ?string $internalReason = null): ContractCharge
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['المبلغ يجب أن يكون أكبر من صفر.']]);
        }
        $message = trim($message);
        if ($message === '') {
            throw ValidationException::withMessages(['message' => ['اكتب رسالة واضحة للعميل — سيقرأها كما هي.']]);
        }

        $charge = ContractCharge::query()->create([
            'contract_id' => $contract->id,
            'kind' => ContractCharge::KIND_EXTRA_FEE,
            'amount' => round($amount, 2),
            'message' => $message,
            'internal_reason' => $internalReason,
            'status' => ContractCharge::STATUS_PENDING,
            'created_by' => $employee?->id,
        ]);
        $charge->forceFill(['payment_key' => Payment::chargeKey((string) $contract->uuid, (int) $charge->id)])->save();

        $this->flow->activity($contract, 'charge_created', $employee, null, [
            'charge_id' => $charge->id, 'kind' => $charge->kind, 'amount' => (float) $charge->amount, 'message' => $message,
        ], 'employee', 'رسوم إضافية '.$this->money((float) $charge->amount).' ر.س — '.$message);

        try {
            $this->customers->chargePaymentRequested($contract, $charge, $this->customerPayUrl($contract, $charge));
        } catch (\Throwable $e) {
            Log::warning('extra fee notification failed', ['charge_id' => $charge->id, 'error' => $e->getMessage()]);
        }

        return $charge->fresh();
    }

    public function cancel(Contract $contract, ContractCharge $charge, ?Employee $employee): ContractCharge
    {
        if (! $charge->isPending()) {
            throw ValidationException::withMessages(['charge' => ['الرسوم ليست معلّقة.']]);
        }
        $charge->forceFill(['status' => ContractCharge::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $employee?->id])->save();
        $this->flow->activity($contract, 'charge_cancelled', $employee, null, ['charge_id' => $charge->id, 'kind' => $charge->kind, 'amount' => (float) $charge->amount], 'employee', 'إلغاء '.$charge->kindLabel().' '.$this->money((float) $charge->amount).' ر.س');

        return $charge->fresh();
    }

    /**
     * رابط Moyasar لمبلغ الرسم فقط + رسالة واتساب (قالب charge_payment_request).
     *
     * @return array{payment_url: string, whatsapp_url: string|null, message: string, phone: string|null, charge: array<string, mixed>, test_mode: bool}
     *
     * @throws ValidationException
     */
    public function paymentLink(Contract $contract, ContractCharge $charge, ?Employee $employee, string $client = 'web'): array
    {
        if (! $charge->isPending()) {
            throw ValidationException::withMessages(['charge' => ['الرسوم ليست معلّقة — لا يمكن توليد رابط دفع.']]);
        }

        $gateway = app(MoyasarPaymentService::class);
        $key = $charge->paymentKey();
        $testMode = false;

        if ($gateway->isTestMode()) {
            // محلياً بلا بوابة: تُسجَّل الدفعة فوراً (نفس سلوك الدفع الأصلي في وضع الاختبار).
            $this->settleWithPayment($charge, $this->recordSimulatedPayment($contract, $charge), 'test');
            $paymentUrl = $this->customerReturnUrl($contract, $charge, true);
            $testMode = true;
        } else {
            $invoice = $gateway->createChargeInvoice($charge, $contract, $client);
            $paymentUrl = (string) $invoice['payment_url'];
            $charge->forceFill(['moyasar_payment_id' => $invoice['invoice_id'] ?? $charge->moyasar_payment_id, 'payment_key' => $key])->save();
        }

        $whatsapp = $this->whatsapp($contract, $charge, $paymentUrl);
        $this->customers->logExternal($contract, 'charge_payment_request', 'whatsapp', 'واتساب: طلب دفع '.$charge->kindLabel(), $whatsapp['message']);
        $this->flow->activity($contract, 'charge_link_sent', $employee, null, ['charge_id' => $charge->id, 'amount' => (float) $charge->amount, 'payment_url' => $paymentUrl], 'employee', 'رابط دفع '.$charge->kindLabel().' '.$this->money((float) $charge->amount).' ر.س');

        return [
            'payment_url' => $paymentUrl,
            'whatsapp_url' => $whatsapp['url'],
            'message' => $whatsapp['message'],
            'phone' => $whatsapp['phone'],
            'charge' => $this->paymentState->chargeArray($charge->fresh(['creator']), $contract),
            'test_mode' => $testMode,
        ];
    }

    /**
     * رابط دفع للعميل (من الموقع/التطبيق) لرسمه المعلّق.
     *
     * @return array{payment_url: string, charge: array<string, mixed>, test_mode: bool}
     */
    public function customerPaymentLink(Contract $contract, ContractCharge $charge, string $client = 'web'): array
    {
        if (! $charge->isPending()) {
            throw ValidationException::withMessages(['charge' => ['هذه الرسوم مدفوعة أو ملغاة.']]);
        }
        $gateway = app(MoyasarPaymentService::class);
        if ($gateway->isTestMode()) {
            $this->settleWithPayment($charge, $this->recordSimulatedPayment($contract, $charge), 'test');

            return ['payment_url' => $this->customerReturnUrl($contract, $charge, true), 'charge' => $this->paymentState->chargeArray($charge->fresh(['creator']), $contract), 'test_mode' => true];
        }
        $invoice = $gateway->createChargeInvoice($charge, $contract, $client);
        $charge->forceFill(['moyasar_payment_id' => $invoice['invoice_id'] ?? $charge->moyasar_payment_id])->save();

        return ['payment_url' => (string) $invoice['payment_url'], 'charge' => $this->paymentState->chargeArray($charge->fresh(['creator']), $contract), 'test_mode' => false];
    }

    /**
     * تسوية من البوابة (webhook/callback): دفعة ناجحة بمفتاح chg-{uuid}-{id}.
     */
    public function settleFromPaymentKey(string $key): ?ContractCharge
    {
        $parsed = Payment::parseChargeKey($key);
        if ($parsed === null || ! SchemaCache::hasTable('contract_charges')) {
            return null;
        }
        $charge = ContractCharge::query()->find($parsed['charge_id']);
        if ($charge === null) {
            return null;
        }
        $payment = Payment::query()->where('contract_uuid', $key)->where('status', 'success')->latest('id')->first();
        if ($payment === null) {
            return $charge;
        }
        if ($charge->status === ContractCharge::STATUS_PAID) {
            return $charge;
        }

        $this->settleWithPayment($charge, $payment, 'moyasar');

        return $charge->fresh();
    }

    /**
     * تسجيل الدفعة على الرسم (Moyasar أو حوالة) وانعكاسها: الحالة، الفاتورة، النشاط، الإشعارات.
     */
    public function settleWithPayment(ContractCharge $charge, Payment $payment, string $source = 'moyasar', ?Employee $employee = null): void
    {
        $contract = $charge->contract ?? Contract::query()->find($charge->contract_id);
        if ($contract === null) {
            return;
        }

        DB::transaction(function () use ($charge, $payment, $contract) {
            $payment->forceFill([
                'kind' => $charge->kind,
                'charge_id' => $charge->id,
                'contract_id' => $contract->id,
            ])->save();
            $charge->forceFill([
                'status' => ContractCharge::STATUS_PAID,
                'payment_id' => $payment->id,
                'moyasar_payment_id' => $payment->gateway_payment_id ?: $charge->moyasar_payment_id,
                'paid_at' => now(),
            ])->save();
        });

        $amountLabel = $this->money((float) $charge->amount);
        $this->flow->activity($contract, 'charge_paid', $employee, null, [
            'charge_id' => $charge->id, 'kind' => $charge->kind, 'amount' => (float) $charge->amount, 'payment_id' => $payment->id, 'method' => $payment->payment_method,
        ], $employee ? 'employee' : 'system', 'تم دفع '.$charge->kindLabel().' '.$amountLabel.' ر.س ('.($payment->payment_method === Payment::METHOD_BANK_TRANSFER ? 'حوالة' : 'Moyasar').')');

        try {
            app(\App\Services\ContractInvoiceService::class)->forContract($contract->fresh());
        } catch (\Throwable $e) {
            Log::warning('invoice refresh after charge failed', ['charge_id' => $charge->id, 'error' => $e->getMessage()]);
        }

        try {
            $this->customers->chargePaid($contract, $charge);
        } catch (\Throwable $e) {
            Log::warning('charge paid customer notification failed', ['charge_id' => $charge->id, 'error' => $e->getMessage()]);
        }

        $this->notifyEmployeesChargePaid($contract, $charge);
    }

    /**
     * دفعة اختبار محلية (بلا بوابة) للرسم.
     */
    public function recordSimulatedPayment(Contract $contract, ContractCharge $charge): Payment
    {
        return Payment::query()->create([
            'name' => 'Charge '.$charge->id.' contract '.$contract->uuid,
            'amount' => round((float) $charge->amount, 2),
            'contract_uuid' => $charge->paymentKey(),
            'contract_id' => $contract->id,
            'charge_id' => $charge->id,
            'kind' => $charge->kind,
            'tran_currency' => 'SAR',
            'payment_method' => 'test',
            'payment_brand' => 'test',
            'status' => 'success',
            'payment_date' => now()->toDateString(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function forCustomer(Contract $contract): array
    {
        return $this->paymentState->charges($contract)
            ->filter(fn (ContractCharge $c) => $c->status !== ContractCharge::STATUS_CANCELLED)
            ->map(fn (ContractCharge $c) => [
                'id' => $c->id,
                'kind' => $c->kind,
                'kind_label' => $c->kindLabel(),
                'amount' => (float) $c->amount,
                'message' => $c->message,
                'status' => $c->status,
                'status_label' => $c->statusLabel(),
                'payment_url' => $c->isPending() ? $this->customerPayUrl($contract, $c) : null,
                'paid_at' => $c->paid_at?->toIso8601String(),
                'created_at' => $c->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function forAdmin(Contract $contract): array
    {
        return $this->paymentState->charges($contract)->map(fn (ContractCharge $c) => $this->paymentState->chargeArray($c, $contract))->values()->all();
    }

    public function customerPayUrl(Contract $contract, ContractCharge $charge): string
    {
        return route('v2.contracts.charges.pay', ['uuid' => (string) $contract->uuid, 'cid' => $charge->id]);
    }

    /** الصفحة التي يعود إليها العميل بعد الدفع (الرابط الذكي للطلب مع معرّف الرسم). */
    public function customerReturnUrl(Contract $contract, ContractCharge $charge, bool $paid): string
    {
        return SmartLink::for($contract).'?charge='.$charge->id.'&status='.($paid ? 'success' : 'failed');
    }

    /**
     * @return array{phone: string|null, message: string, url: string|null}
     */
    public function whatsapp(Contract $contract, ContractCharge $charge, string $paymentUrl): array
    {
        $vars = $this->templates->varsFor($contract, [
            'amount' => $this->money((float) $charge->amount),
            'reason' => (string) $charge->message,
            'payment_url' => $paymentUrl,
        ]);
        $rendered = $this->templates->render('charge_payment_request', 'whatsapp', $vars, "طلبك رقم {order}: {reason}\nالمبلغ: {amount} ر.س\n{payment_url}");
        $message = (string) ($rendered['body'] ?? '');
        $phone = $this->stages->customerPhone($contract);

        return ['phone' => $phone, 'message' => $message, 'url' => $phone !== null ? 'https://wa.me/'.$phone.'?text='.rawurlencode($message) : null];
    }

    /**
     * سبب تلقائي مقروء من الحقول المتغيّرة (مثل «تغيير نوع المستند: إلكتروني → ورقي»).
     *
     * @param  array<string, mixed>  $changed
     */
    private function autoReason(Contract $contract, array $changed): ?string
    {
        $parts = [];
        foreach ($changed as $field => $info) {
            if (! is_string($field)) {
                continue;
            }
            $before = is_array($info) ? ($info['before'] ?? null) : null;
            $after = is_array($info) ? ($info['after'] ?? $info) : $info;
            $label = is_array($info) && isset($info['label']) ? (string) $info['label'] : null;
            $parts[] = match ($field) {
                'instrument_type' => 'تغيير نوع المستند: '.$this->instrumentLabel($before).' → '.$this->instrumentLabel($after),
                'contract_type' => 'تغيير نوع العقد: '.Contract::contractTypeLabel((string) $before, 'ar').' → '.Contract::contractTypeLabel((string) $after, 'ar'),
                'duration_preset', 'total_months', 'duration_years', 'duration_months', 'contract_term_in_years' => 'تغيير مدة العقد',
                'electricity_meter_ownership', 'water_meter_ownership' => 'تغيير ملكية العداد',
                default => $label !== null ? 'تعديل '.$label : null,
            };
        }
        $parts = array_values(array_unique(array_filter($parts)));

        return $parts === [] ? null : implode('، ', $parts);
    }

    private function instrumentLabel(mixed $key): string
    {
        $key = is_string($key) ? trim($key) : '';
        if ($key === '') {
            return '—';
        }

        return $key === 'electronic' ? 'إلكتروني' : ($key === 'old_handwritten' ? 'ورقي' : Contract::instrumentTypeLabel($key, 'ar'));
    }

    private function notifyEmployeesChargePaid(Contract $contract, ContractCharge $charge): void
    {
        $contract->loadMissing('receivedContract.employee');
        $employeeId = $contract->receivedContract?->employee_id ?: $charge->created_by;
        $title = 'تم دفع '.$charge->kindLabel();
        $body = 'الطلب #'.$contract->uuid.': دفع العميل '.$this->money((float) $charge->amount).' ر.س ('.$charge->message.') — يمكنك إكمال التوثيق.';
        $data = ['type' => 'charge_paid', 'contract_id' => (string) $contract->id, 'contract_uuid' => (string) $contract->uuid, 'charge_id' => (string) $charge->id];

        try {
            if (SchemaCache::hasTable('employee_notifications')) {
                EmployeeNotification::query()->create([
                    'employee_id' => $employeeId ?: null,
                    'contract_id' => $contract->id,
                    'kind' => 'charge_paid',
                    'title' => $title,
                    'body' => $body,
                    'url' => '/home/orders/'.$contract->id,
                    'data' => $data,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('employee notification store failed', ['error' => $e->getMessage()]);
        }
        try {
            $firebase = app(FirebaseNotificationService::class);
            if ($employeeId) {
                $firebase->sendToEmployee((int) $employeeId, $title, $body, $data);
            }
            $firebase->sendToTopic((string) config('services.firebase.employees_topic', 'employees'), $title, $body, $data);
        } catch (\Throwable $e) {
            Log::warning('employee push failed', ['error' => $e->getMessage()]);
        }
        $this->customers->logExternalEmployee($contract, 'charge_paid', $title, $body);
    }

    private function money(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
