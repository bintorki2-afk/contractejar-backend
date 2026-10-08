<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Invoice;
use App\Models\LessorChangeRequest;
use App\Models\Payment;
use App\Models\RefundableContract;
use App\Support\ContractPricing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * فاتورة الطلب (الموقع + التطبيق + لوحة التحكم).
 *
 * البنود تُبنى من {@see ContractPricing} (المصدر الوحيد للأسعار) ولا تُقرأ أبداً من جداول
 * الأسعار القديمة (services-pricing / paperwork). عند وجود دفعة ناجحة تُحفَظ لقطة البنود في
 * `invoices.lines` حتى لا تتغيّر الفاتورة لاحقاً عند تعديل الأسعار من لوحة التحكم.
 */
class ContractInvoiceService
{
    public const PLATFORM_NAME = 'عقد إيجار';

    public const PLATFORM_SUBTITLE = 'منصة توثيق عقود الإيجار';

    public const LINES_VERSION = 1;

    public const LESSOR_CHANGE_LINE_LABEL = 'رسوم خدمة تغيير المؤجر';

    /**
     * Build (and persist if needed) the invoice payload for a contract.
     *
     * @param  bool  $persist  false = preview only: never creates/updates an invoice row
     *                         (admin views of unpaid orders).
     * @return array<string, mixed>
     */
    public function forContract(Contract $contract, bool $persist = true): array
    {
        $contract->loadMissing(['user', 'contractStatus', 'refundableContract']);

        $payment = $this->resolveSuccessfulPayment($contract);
        $invoice = $persist
            ? $this->ensureInvoiceRecord($contract, $payment)
            : $this->existingInvoice($contract);

        $breakdown = $this->resolveBreakdown($contract, $payment, $invoice, $persist);
        $status = $this->resolveStatus($contract);
        $issuedAt = $this->resolveIssuedAt($contract, $payment, $invoice);
        $total = (float) $breakdown['total'];

        return array_merge($this->basePayload(
            kind: Invoice::KIND_CONTRACT,
            invoice: $invoice,
            orderNumber: (string) ($contract->uuid ?: $contract->id),
            legacyOrderNo: (string) $contract->id,
            customerName: (string) ($contract->user?->name ?? ''),
            issuedAt: $issuedAt,
            reference: $this->resolveReferenceNumber($contract, $payment, $invoice),
            breakdown: $breakdown,
            status: $status,
            isPaid: (bool) $contract->is_completed,
        ), [
            'contract_id' => $contract->id,
            'contract_uuid' => (string) $contract->uuid,
            'lessor_change_request_id' => null,
            'contract_type' => (string) $contract->contract_type,
            'contract_type_label' => Contract::contractTypeLabel((string) $contract->contract_type),
            'total_amount' => $total,
            'total_amount_label' => $this->formatAmountLabel($total),
        ]);
    }

    /**
     * فاتورة طلب «تغيير المؤجر» (بند واحد: رسوم الخدمة).
     *
     * @return array<string, mixed>
     */
    public function forLessorChange(LessorChangeRequest $request, bool $persist = true): array
    {
        $request->loadMissing('user');

        $payment = $this->resolveSuccessfulPaymentByUuid((string) $request->uuid);
        $invoice = $persist
            ? $this->ensureLessorChangeInvoiceRecord($request, $payment)
            : Invoice::query()->where('lessor_change_request_id', $request->id)->latest('id')->first();

        $raw = ($invoice && is_array($invoice->lines) && ! empty($invoice->lines['items']))
            ? $invoice->lines
            : $this->lessorChangeBreakdown($request);
        $breakdown = $this->reconcileWithPayment($raw, $payment, 'lessor_change:'.$request->uuid);

        if ($persist && $invoice && $payment && empty($invoice->lines)) {
            $this->storeLines($invoice, $raw, (float) $breakdown['total']);
        }

        $paid = $request->isPaid();
        $status = $request->status === 'cancelled'
            ? ['status' => 'cancelled', 'status_label' => 'ملغية', 'status_color' => '#6B7280']
            : ($paid
                ? ['status' => 'paid', 'status_label' => 'مدفوعة', 'status_color' => '#16A34A']
                : ['status' => 'unpaid', 'status_label' => 'غير مدفوعة', 'status_color' => '#6B7280']);

        $issuedAt = $payment?->created_at
            ? Carbon::parse($payment->created_at)
            : ($request->paid_at ? Carbon::parse($request->paid_at) : Carbon::parse($request->created_at ?? now()));

        $total = (float) $breakdown['total'];

        return array_merge($this->basePayload(
            kind: Invoice::KIND_LESSOR_CHANGE,
            invoice: $invoice,
            orderNumber: (string) $request->uuid,
            legacyOrderNo: (string) $request->uuid,
            customerName: (string) ($request->user?->name ?? ''),
            issuedAt: $issuedAt,
            reference: $payment ? $this->paymentReference($payment) : '71'.str_pad((string) $request->id, 8, '0', STR_PAD_LEFT),
            breakdown: $breakdown,
            status: $status,
            isPaid: $paid,
        ), [
            'contract_id' => null,
            'contract_uuid' => null,
            'lessor_change_request_id' => $request->id,
            'contract_type' => 'lessor_change',
            'contract_type_label' => 'تغيير المؤجر',
            'total_amount' => $total,
            'total_amount_label' => $this->formatAmountLabel($total),
        ]);
    }

    /**
     * Invoice payload for an existing invoice row (list endpoint).
     *
     * @return array<string, mixed>|null
     */
    public function forInvoice(Invoice $invoice): ?array
    {
        if ($invoice->isLessorChange()) {
            $request = $invoice->relationLoaded('lessorChangeRequest')
                ? $invoice->lessorChangeRequest
                : $invoice->lessorChangeRequest()->first();

            return $request ? $this->forLessorChange($request) : null;
        }

        $contract = $invoice->relationLoaded('contract')
            ? $invoice->contract
            : $invoice->contract()->first();

        return $contract ? $this->forContract($contract) : null;
    }

    public function ensureInvoiceRecord(Contract $contract, ?Payment $payment = null): Invoice
    {
        $existing = $this->existingInvoice($contract);

        if ($existing) {
            if ($existing->user_id === null && $contract->user_id) {
                $existing->forceFill(['user_id' => (int) $contract->user_id])->save();
            }

            return $existing;
        }

        $payment ??= $this->resolveSuccessfulPayment($contract);
        $raw = $this->contractBreakdown($contract);
        $breakdown = $this->reconcileWithPayment($raw, $payment, 'contract:'.$contract->uuid);
        $issuedAt = $this->resolveIssuedAt($contract, $payment, null);

        return Invoice::query()->create([
            'invoice_number' => 'INV-'.$contract->id,
            'order_number' => (string) $contract->id,
            'date' => $issuedAt->toDateString(),
            'customer_phone' => $contract->user?->mobile ?? null,
            'description' => $this->feeLineDescription($contract, (int) ($raw['duration_months'] ?? 0)),
            'rental_fees' => 0,
            'service_fees' => (float) ($raw['fee'] ?? 0),
            'total_amount' => (float) $breakdown['total'],
            // اللقطة (البنود المحسوبة) تُحفظ فقط بعد الدفع؛ قبل الدفع تبقى الأسعار حيّة.
            'lines' => $payment ? $raw : null,
            'kind' => Invoice::KIND_CONTRACT,
            'contract_id' => $contract->id,
            'user_id' => $contract->user_id ? (int) $contract->user_id : null,
        ]);
    }

    public function ensureLessorChangeInvoiceRecord(LessorChangeRequest $request, ?Payment $payment = null): Invoice
    {
        $existing = Invoice::query()->where('lessor_change_request_id', $request->id)->latest('id')->first();
        if ($existing) {
            return $existing;
        }

        $payment ??= $this->resolveSuccessfulPaymentByUuid((string) $request->uuid);
        $raw = $this->lessorChangeBreakdown($request);
        $breakdown = $this->reconcileWithPayment($raw, $payment, 'lessor_change:'.$request->uuid);
        $issuedAt = $payment?->created_at ? Carbon::parse($payment->created_at) : Carbon::parse($request->paid_at ?? $request->created_at ?? now());

        return Invoice::query()->create([
            'invoice_number' => 'INV-LC-'.$request->id,
            'order_number' => (string) $request->uuid,
            'date' => $issuedAt->toDateString(),
            'customer_phone' => $request->mobile ?? $request->user?->mobile ?? null,
            'description' => self::LESSOR_CHANGE_LINE_LABEL,
            'rental_fees' => 0,
            'service_fees' => (float) $raw['fee'],
            'total_amount' => (float) $breakdown['total'],
            'lines' => $payment ? $raw : null,
            'kind' => Invoice::KIND_LESSOR_CHANGE,
            'lessor_change_request_id' => $request->id,
            'user_id' => $request->user_id ? (int) $request->user_id : null,
        ]);
    }

    /**
     * بنود الفاتورة الحيّة من ContractPricing (بدون أي قراءة من جداول الأسعار القديمة).
     *
     * @return array<string, mixed>
     */
    public function contractBreakdown(Contract $contract): array
    {
        $pricing = ContractPricing::for($contract);
        $months = (int) ($pricing['doc_fee_summary']['total_months'] ?? $contract->total_months ?? 0);
        $meter = $pricing['meter_fees'];

        $items = [];
        $items[] = $this->line('fee', $this->feeLineDescription($contract, $months), (float) $pricing['fee']);

        if ((float) $pricing['document_surcharge'] > 0) {
            $items[] = $this->line('document_surcharge', 'رسوم المستندات الإضافية', (float) $pricing['document_surcharge']);
        }
        if ((float) ($meter['electricity_meter_fee'] ?? 0) > 0) {
            $items[] = $this->line('electricity_meter', 'رسوم نقل عداد الكهرباء باسم المستأجر', (float) $meter['electricity_meter_fee']);
        }
        if ((float) ($meter['water_meter_fee'] ?? 0) > 0) {
            $items[] = $this->line('water_meter', 'رسوم نقل عداد المياه باسم المستأجر', (float) $meter['water_meter_fee']);
        }

        $subtotal = round(array_sum(array_map(static fn (array $i) => (float) $i['amount'], $items)), 2);
        $vat = round((float) $pricing['vat'], 2);
        $discount = round((float) $pricing['coupon'], 2);
        $couponCode = $discount > 0 ? $this->couponCode($contract) : null;

        if ($vat > 0) {
            $rate = rtrim(rtrim(number_format((float) $pricing['vat_rate'], 2, '.', ''), '0'), '.');
            $items[] = $this->line('vat', "ضريبة القيمة المضافة ({$rate}%)", $vat);
        }
        if ($discount > 0) {
            $items[] = $this->line('coupon', 'خصم كوبون'.($couponCode ? ' '.$couponCode : ''), -$discount);
        }

        return [
            'version' => self::LINES_VERSION,
            'items' => $items,
            'fee' => round((float) $pricing['fee'], 2),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'coupon_code' => $couponCode,
            'vat' => $vat,
            'vat_rate' => (float) $pricing['vat_rate'],
            'total' => round(max(0, $subtotal - $discount + $vat), 2),
            'duration_months' => $months,
            'billable_years' => (int) ($pricing['doc_fee_summary']['billable_years'] ?? 0),
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lessorChangeBreakdown(LessorChangeRequest $request): array
    {
        $fee = round((float) $request->fee, 2);
        if ($fee <= 0) {
            $fee = \App\Modules\LessorChange\Controllers\Api\V2\LessorChangeController::currentFee();
        }

        return [
            'version' => self::LINES_VERSION,
            'items' => [$this->line('lessor_change_fee', self::LESSOR_CHANGE_LINE_LABEL, $fee)],
            'fee' => $fee,
            'subtotal' => $fee,
            'discount' => 0.0,
            'coupon_code' => null,
            'vat' => 0.0,
            'vat_rate' => 0.0,
            'total' => $fee,
            'duration_months' => 0,
            'billable_years' => 0,
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * نص المدة: سنة / سنتين / 3 سنوات / سنة و3 أشهر / 6 أشهر.
     */
    public static function durationLabel(int $months): string
    {
        if ($months <= 0) {
            return '';
        }

        $years = intdiv($months, 12);
        $rest = $months % 12;

        $yearLabel = match (true) {
            $years === 0 => '',
            $years === 1 => 'سنة',
            $years === 2 => 'سنتين',
            $years <= 10 => "{$years} سنوات",
            default => "{$years} سنة",
        };

        $monthLabel = match (true) {
            $rest === 0 => '',
            $rest === 1 => 'شهر',
            $rest === 2 => 'شهرين',
            $rest <= 10 => "{$rest} أشهر",
            default => "{$rest} شهر",
        };

        if ($yearLabel !== '' && $monthLabel !== '') {
            return "{$yearLabel} و{$monthLabel}";
        }

        return $yearLabel !== '' ? $yearLabel : $monthLabel;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveBreakdown(Contract $contract, ?Payment $payment, ?Invoice $invoice, bool $persist): array
    {
        // فاتورة محفوظة ببنودها: البنود لا تتغيّر أبداً (المطابقة مع مبلغ الدفعة تتم عند القراءة).
        if ($invoice && is_array($invoice->lines) && ! empty($invoice->lines['items'])) {
            return $this->reconcileWithPayment($invoice->lines, $payment, 'contract:'.$contract->uuid);
        }

        $raw = $this->contractBreakdown($contract);
        $breakdown = $this->reconcileWithPayment($raw, $payment, 'contract:'.$contract->uuid);

        // فاتورة قديمة (قبل هذا التحديث) أو أُنشئت قبل الدفع: نحفظ اللقطة أول مرة بعد الدفع.
        if ($persist && $invoice && $payment) {
            $this->storeLines($invoice, $raw, (float) $breakdown['total']);
        }

        return $breakdown;
    }

    /**
     * @param  array<string, mixed>  $raw  البنود المحسوبة (قبل المطابقة مع الدفعة)
     */
    private function storeLines(Invoice $invoice, array $raw, float $total): void
    {
        try {
            $invoice->forceFill([
                'lines' => $raw,
                'service_fees' => (float) ($raw['fee'] ?? $invoice->service_fees),
                'total_amount' => $total,
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('Invoice lines snapshot failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * المبلغ المعتمد هو مبلغ الدفعة الناجحة؛ عند الاختلاف نُسجّل تحذيراً ونعرض مبلغ الدفعة.
     *
     * @param  array<string, mixed>  $breakdown
     * @return array<string, mixed>
     */
    private function reconcileWithPayment(array $breakdown, ?Payment $payment, string $ref): array
    {
        $computed = round((float) ($breakdown['computed_total'] ?? $breakdown['total'] ?? 0), 2);
        $breakdown['computed_total'] = $computed;
        $breakdown['total'] = $computed;
        $breakdown['amount_mismatch'] = false;
        $breakdown['paid_amount'] = null;

        if (! $payment || $payment->amount === null) {
            return $breakdown;
        }

        $paid = round((float) $payment->amount, 2);
        $breakdown['paid_amount'] = $paid;

        if (abs($paid - $computed) > 0.01) {
            Log::warning('Invoice total differs from the successful payment amount', [
                'ref' => $ref,
                'computed_total' => $computed,
                'paid_amount' => $paid,
                'payment_id' => $payment->id,
            ]);
            $breakdown['amount_mismatch'] = true;
            $breakdown['total'] = $paid;
        }

        return $breakdown;
    }

    /**
     * @param  array<string, mixed>  $breakdown
     * @param  array{status: string, status_label: string, status_color: string}  $status
     * @return array<string, mixed>
     */
    private function basePayload(
        string $kind,
        ?Invoice $invoice,
        string $orderNumber,
        string $legacyOrderNo,
        string $customerName,
        Carbon $issuedAt,
        string $reference,
        array $breakdown,
        array $status,
        bool $isPaid,
    ): array {
        $items = [];
        foreach (array_values($breakdown['items'] ?? []) as $index => $item) {
            $amount = round((float) ($item['amount'] ?? 0), 2);
            $items[] = [
                'index' => $index + 1,
                'key' => (string) ($item['key'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'amount' => $amount,
                'amount_label' => $this->formatAmountLabel($amount),
                'is_discount' => $amount < 0,
            ];
        }

        $subtotal = round((float) ($breakdown['subtotal'] ?? 0), 2);
        $discount = round((float) ($breakdown['discount'] ?? 0), 2);
        $vat = round((float) ($breakdown['vat'] ?? 0), 2);
        $total = round((float) ($breakdown['total'] ?? 0), 2);

        return [
            'id' => $invoice?->id,
            'kind' => $kind,
            'platform_name' => self::PLATFORM_NAME,
            'platform_subtitle' => self::PLATFORM_SUBTITLE,
            'title' => 'الفاتورة',
            'invoice_number' => $invoice?->invoice_number,
            'invoice_no' => $invoice?->invoice_number,
            'date' => $issuedAt->format('Y/m/d'),
            'time' => $issuedAt->locale('ar')->translatedFormat('h:i a'),
            'datetime_label' => $issuedAt->format('Y/m/d').' · '.$issuedAt->locale('ar')->translatedFormat('h:i a'),
            'issued_at' => $issuedAt->toIso8601String(),
            'reference_number' => $reference,
            'customer_name' => $customerName,
            'order_number' => '#'.$orderNumber,
            'order_no' => $legacyOrderNo,
            'items' => $items,
            'subtotal' => $subtotal,
            'subtotal_label' => $this->formatAmountLabel($subtotal),
            'discount' => $discount,
            'discount_label' => $discount > 0 ? '- '.$this->formatAmountLabel($discount) : $this->formatAmountLabel(0),
            'coupon_code' => $breakdown['coupon_code'] ?? null,
            'vat' => $vat,
            'vat_rate' => (float) ($breakdown['vat_rate'] ?? 0),
            'vat_label' => $vat > 0 ? $this->formatAmountLabel($vat) : ContractPricing::VAT_FREE_LABEL,
            'total_amount' => $total,
            'total_amount_label' => $this->formatAmountLabel($total),
            'total_due_label' => 'الإجمالي المستحق',
            'amount_mismatch' => (bool) ($breakdown['amount_mismatch'] ?? false),
            'computed_total' => $breakdown['computed_total'] ?? null,
            'currency' => 'ريال',
            'status' => $status['status'],
            'status_label' => $status['status_label'],
            'status_color' => $status['status_color'],
            'print_label' => 'طباعة / تحميل الفاتورة',
            'is_paid' => $isPaid,
            'is_refunded' => $status['status'] === 'refunded',
        ];
    }

    /**
     * @return array{key: string, description: string, quantity: int, amount: float}
     */
    private function line(string $key, string $description, float $amount): array
    {
        return ['key' => $key, 'description' => $description, 'quantity' => 1, 'amount' => round($amount, 2)];
    }

    private function couponCode(Contract $contract): ?string
    {
        if (! filled($contract->uuid)) {
            return null;
        }

        $usage = CouponUsage::query()->where('contract_uuid', $contract->uuid)->first();
        $coupon = $usage ? Coupon::query()->find($usage->coupon_id) : null;

        return $coupon?->code_coupon ? (string) $coupon->code_coupon : null;
    }

    private function existingInvoice(Contract $contract): ?Invoice
    {
        return Invoice::query()
            ->where('contract_id', $contract->id)
            ->latest('id')
            ->first();
    }

    private function resolveSuccessfulPayment(Contract $contract): ?Payment
    {
        if (! filled($contract->uuid)) {
            return null;
        }

        $payment = $this->resolveSuccessfulPaymentByUuid((string) $contract->uuid);
        if ($payment !== null) {
            return $payment;
        }

        // لا توجد دفعة ناجحة في جدول payments، لكن قد يكون الطلب مدفوعاً عبر موظف
        // (ContractPaidByEmployee.is_paid) بلا صف payment. نُنشئ كائن دفعة غير محفوظ
        // بالمبلغ الفعلي حتى لا تظهر الفاتورة بإجمالي 0 لطلب مدفوع، ويُرفع علم عدم التطابق
        // عند اختلافه عن السعر المحسوب. (لا نعتبر الدفعات الفاشلة دفعاً أبداً.)
        $employeePaid = \App\Models\ContractPaidByEmployee::query()
            ->where('contract_uuid', (string) $contract->uuid)
            ->where('is_paid', true)
            ->whereNotNull('amount')
            ->where('amount', '>', 0)
            ->latest('id')
            ->first();

        if ($employeePaid === null) {
            return null;
        }

        return new Payment([
            'contract_uuid' => (string) $contract->uuid,
            'amount' => (float) $employeePaid->amount,
            'status' => 'success',
            'payment_method' => 'employee',
        ]);
    }

    private function resolveSuccessfulPaymentByUuid(string $uuid): ?Payment
    {
        if ($uuid === '') {
            return null;
        }

        return Payment::query()
            ->successfulMatchingContractUuid($uuid)
            ->latest('id')
            ->first();
    }

    /**
     * @return array{status: string, status_label: string, status_color: string}
     */
    private function resolveStatus(Contract $contract): array
    {
        $refund = $contract->relationLoaded('refundableContract')
            ? $contract->refundableContract
            : RefundableContract::query()->where('contract_id', $contract->id)->latest('id')->first();

        if ($refund && $refund->is_refunded) {
            return [
                'status' => 'refunded',
                'status_label' => 'مُسترجعة',
                'status_color' => '#DC2626',
            ];
        }

        if ((int) $contract->contract_status_id === ContractStatus::RETURN_ID) {
            return [
                'status' => 'returned',
                'status_label' => 'مسترجع',
                'status_color' => '#DC2626',
            ];
        }

        if ((bool) $contract->is_completed) {
            return [
                'status' => 'paid',
                'status_label' => 'مدفوعة',
                'status_color' => '#16A34A',
            ];
        }

        return [
            'status' => 'unpaid',
            'status_label' => 'غير مدفوعة',
            'status_color' => '#6B7280',
        ];
    }

    private function feeLineDescription(Contract $contract, int $months): string
    {
        // بنود الفاتورة عربية دائماً (مستند رسمي) بغض النظر عن لغة الطلب.
        $typeLabel = Contract::contractTypeLabel((string) $contract->contract_type, 'ar');
        $duration = self::durationLabel($months);

        return 'رسوم توثيق عقد إيجار '.$typeLabel.($duration !== '' ? ' — '.$duration : '');
    }

    private function resolveIssuedAt(Contract $contract, ?Payment $payment, ?Invoice $invoice): Carbon
    {
        if ($payment?->payment_date) {
            $date = Carbon::parse($payment->payment_date);
            if ($payment->created_at) {
                $date->setTimeFrom($payment->created_at);
            }

            return $date;
        }

        if ($invoice?->date) {
            $date = Carbon::parse($invoice->date);
            if ($invoice->created_at) {
                $date->setTimeFrom($invoice->created_at);
            }

            return $date;
        }

        if ($contract->updated_at && $contract->is_completed) {
            return Carbon::parse($contract->updated_at);
        }

        return Carbon::parse($contract->created_at ?? now());
    }

    private function resolveReferenceNumber(Contract $contract, ?Payment $payment, ?Invoice $invoice): string
    {
        if ($payment) {
            return $this->paymentReference($payment);
        }

        return '70'.str_pad((string) $contract->id, 8, '0', STR_PAD_LEFT);
    }

    private function paymentReference(Payment $payment): string
    {
        if (filled($payment->name) && preg_match('/^[A-Za-z0-9_\-]+$/', (string) $payment->name)) {
            // Gateway / transaction reference when stored on payment.name
            return (string) $payment->name;
        }

        return (string) $payment->id;
    }

    private function formatAmountLabel(float $amount): string
    {
        $formatted = rtrim(rtrim(number_format(abs($amount), 2, '.', ''), '0'), '.');

        return ($amount < 0 ? '- ' : '').$formatted.' ريال';
    }
}
