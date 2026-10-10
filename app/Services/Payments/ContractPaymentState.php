<?php

namespace App\Services\Payments;

use App\Models\Contract;
use App\Models\ContractCharge;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\ContractInvoiceService;
use App\Support\ContractPricing;
use App\Support\SchemaCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Support\CustomerLinks;

/**
 * حالة الدفع للطلب (دفعة هـ — 2.1): المصدر الوحيد لـ payment_state و payment_details
 * في اللوحة والموقع والتطبيق. يُحسب حيّاً من ContractPricing + الدفعات + الرسوم + الاسترجاعات.
 *
 *  - due_total   = السعر الحي (ContractPricing::total) + الرسوم الإضافية (معلّقة أو مدفوعة)
 *  - paid_total  = مجموع الدفعات الناجحة (أصلية + رسوم + حوالات)
 *  - refunded_total = مجموع الاسترجاعات الناجحة
 *  - net_total   = paid_total − refunded_total
 *  - outstanding = max(0, due_total − net_total)
 *  - refund_due  = max(0, net_total − due_total)  (الفرق لصالح العميل بعد تعديل يخفّض السعر)
 */
class ContractPaymentState
{
    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PAID = 'paid';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    public const STATUS_REFUNDED = 'refunded';

    public const BLOCK_PAYMENT_REQUIRED = 'payment_required';

    public const BLOCK_CHARGE_PENDING = 'charge_pending';

    public const STATUS_LABELS = [
        self::STATUS_UNPAID => 'غير مدفوع',
        self::STATUS_PAID => 'مدفوع',
        self::STATUS_PARTIALLY_PAID => 'مدفوع جزئياً',
        self::STATUS_PARTIALLY_REFUNDED => 'مسترجع جزئياً',
        self::STATUS_REFUNDED => 'مسترجع',
    ];

    public const METHOD_LABELS = [
        'moyasar' => 'Moyasar',
        'bank_transfer' => 'حوالة',
        'mixed' => 'Moyasar + حوالة',
    ];

    public const BLOCK_MESSAGES = [
        self::BLOCK_PAYMENT_REQUIRED => 'لا يمكن توثيق العقد قبل تسجيل الدفع — ولّد رابط دفع أو سجّل حوالة بنكية مع الإيصال.',
        self::BLOCK_CHARGE_PENDING => 'لا يمكن توثيق العقد وهناك رسوم بانتظار الدفع — حصّل الفرق أولاً أو ألغِ الرسوم.',
    ];

    /**
     * @return array<string, mixed>
     */
    public function state(Contract $contract): array
    {
        $payments = $this->successfulPayments($contract);
        $charges = $this->charges($contract);
        $pending = $charges->where('status', ContractCharge::STATUS_PENDING);

        $originalDue = $this->originalDue($contract);
        // QA-F C2: السعر الحي غير معروف (عقد قديم بلا مدة/رسوم) ⇒ لا نعتبر المستحق صفراً
        // (كان يُظهر «مستحق للعميل» بكامل المدفوع وزر استرجاع). نأخذ لقطة الدفعة الأصلية.
        if ($originalDue <= 0.009) {
            $originalDue = $this->originalPaidSnapshot($payments);
        }
        $extraDue = round((float) $charges->where('kind', ContractCharge::KIND_EXTRA_FEE)
            ->whereIn('status', [ContractCharge::STATUS_PENDING, ContractCharge::STATUS_PAID])->sum('amount'), 2);
        $dueTotal = round($originalDue + $extraDue, 2);

        $paidTotal = round((float) $payments->sum('amount'), 2);
        $refundedTotal = $this->refundedTotal($contract);
        $netTotal = round($paidTotal - $refundedTotal, 2);
        $outstanding = round(max(0, $dueTotal - $netTotal), 2);
        $refundDue = $pending->isEmpty() ? round(max(0, $netTotal - $dueTotal), 2) : 0.0;
        $pendingTotal = round((float) $pending->sum('amount'), 2);

        // QA-F C12: رسم معلّق = المتبقي على العميل دائماً، بنفس منطق summaryForList (القائمة/التتبّع)
        // — كانت الصفحة تقول «مدفوع» والقائمة «مدفوع جزئياً» لنفس الطلب.
        if ($pending->isNotEmpty()) {
            $outstanding = round(max($outstanding, $pendingTotal), 2);
        }
        $labelDue = $pending->isNotEmpty() ? round($netTotal + $outstanding, 2) : $dueTotal;

        $status = $this->status($paidTotal, $refundedTotal, $outstanding);
        $method = $this->method($payments);

        $blockReason = null;
        if ($pending->isNotEmpty()) {
            $blockReason = self::BLOCK_CHARGE_PENDING;
        } elseif ($status === self::STATUS_UNPAID || $status === self::STATUS_REFUNDED || $outstanding > 0.009) {
            $blockReason = self::BLOCK_PAYMENT_REQUIRED;
        }

        return [
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status],
            'method' => $method,
            'method_label' => $method !== null ? self::METHOD_LABELS[$method] : null,
            'due_total' => $dueTotal,
            'original_due' => $originalDue,
            'extra_due' => $extraDue,
            'paid_total' => $paidTotal,
            'outstanding' => $outstanding,
            'refunded_total' => $refundedTotal,
            'net_total' => $netTotal,
            'refund_due' => $refundDue,
            'pending_charges_count' => $pending->count(),
            'pending_charges_total' => $pendingTotal,
            'label' => $this->label($status, $method, $paidTotal, $labelDue, $refundedTotal, $netTotal),
            'can_notarize' => $blockReason === null,
            'notarize_block_reason' => $blockReason,
            'notarize_block_message' => $blockReason !== null ? self::BLOCK_MESSAGES[$blockReason] : null,
            'is_paid' => in_array($status, [self::STATUS_PAID, self::STATUS_PARTIALLY_REFUNDED], true),
            ...$this->refundPending($contract, $refundedTotal),
        ];
    }

    /**
     * QA-F WEB-5 / DASH-25: حالة الطلب «مسترجع» بلا أي استرجاع منفّذ فعلاً ⇒ «بانتظار إعادة المبلغ»
     * (لا نعرض «مدفوع» بجوار «تم الاسترجاع · 0»). لا تغيير في status/الأرقام — الأرقام من الدفعات الفعلية فقط.
     *
     * @return array{refund_pending: bool, refund_pending_amount: float|null, refund_pending_label: string|null}
     */
    private function refundPending(Contract $contract, float $refundedTotal): array
    {
        $none = ['refund_pending' => false, 'refund_pending_amount' => null, 'refund_pending_label' => null];
        try {
            $refundedId = \App\Models\ContractStatus::refundedId();
            if (! $refundedId || (int) $contract->contract_status_id !== (int) $refundedId || $refundedTotal > 0.009) {
                return $none;
            }
            $amount = null;
            if (SchemaCache::hasTable('refundable_contracts')) {
                $row = \App\Models\RefundableContract::query()->where('contract_id', $contract->id)->latest('id')->first();
                $amount = $row && (float) $row->refund_amount > 0 ? round((float) $row->refund_amount, 2) : null;
            }

            return [
                'refund_pending' => true,
                'refund_pending_amount' => $amount,
                'refund_pending_label' => 'مسترجع — بانتظار إعادة المبلغ'.($amount !== null ? ' · '.rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.').' ر.س' : ''),
            ];
        } catch (\Throwable) {
            return $none;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function details(Contract $contract, bool $withInvoiceUrl = true): array
    {
        $state = $this->state($contract);
        $charges = $this->charges($contract);
        $payments = $this->allPayments($contract);
        $refunds = $this->refunds($contract);

        $originalPaid = round((float) $payments->filter(fn (Payment $p) => $p->status === 'success' && $this->paymentKind($p) === Payment::KIND_ORIGINAL)->sum('amount'), 2)
            + round((float) $payments->filter(fn (Payment $p) => $p->status === 'success' && $this->paymentKind($p) === Payment::KIND_BANK_TRANSFER && empty($p->charge_id))->sum('amount'), 2);
        $extraPaid = round((float) $payments->filter(fn (Payment $p) => $p->status === 'success' && ! empty($p->charge_id))->sum('amount'), 2);

        $invoice = SchemaCache::hasTable('invoices')
            ? \App\Models\Invoice::query()->where('contract_id', $contract->id)->latest('id')->first()
            : null;

        // B-3: بنود «ما دفعه العميل» = لقطة الفاتورة (الأصل كما دُفع) + الرسوم المدفوعة − الاسترجاعات،
        // لا السعر الحي (الذي يتضمن بالفعل أثر التعديل الذي غطّاه رسم فرق السعر). مجموعها = totals.net.
        $lines = $this->originalLines($contract, $invoice, $originalPaid);
        foreach ($charges->where('status', ContractCharge::STATUS_PAID) as $charge) {
            $lines[] = [
                'key' => $charge->kind.'_'.$charge->id,
                'label' => ($charge->kind === ContractCharge::KIND_EXTRA_FEE ? 'رسوم إضافية' : 'فرق سعر').(filled($charge->message) ? ' — '.$charge->message : ''),
                'amount' => (float) $charge->amount,
                'kind' => $charge->kind,
                'charge_id' => $charge->id,
            ];
        }
        foreach ($refunds->where('status', Refund::STATUS_SUCCEEDED) as $refund) {
            $lines[] = [
                'key' => 'refund_'.$refund->id,
                'label' => 'استرجاع'.(filled($refund->reason) ? ' — '.$refund->reason : ''),
                'amount' => -1 * (float) $refund->amount,
                'kind' => 'refund',
                'refund_id' => $refund->id,
            ];
        }

        $transactions = [];
        foreach ($payments as $p) {
            $transactions[] = $this->transactionFromPayment($p, $contract);
        }
        foreach ($refunds as $r) {
            $transactions[] = [
                'id' => 'refund-'.$r->id,
                'refund_id' => $r->id,
                'payment_id' => $r->payment_id,
                'kind' => 'refund',
                'kind_label' => 'استرجاع',
                'amount' => -1 * (float) $r->amount,
                'method' => 'moyasar',
                'method_label' => 'Moyasar',
                'status' => $r->status,
                'status_label' => $r->status === Refund::STATUS_SUCCEEDED ? 'تم الاسترجاع' : ($r->status === Refund::STATUS_FAILED ? 'فشل' : 'قيد التنفيذ'),
                'paid_at' => $r->created_at?->toIso8601String(),
                'reference' => $r->moyasar_refund_id,
                'card_last4' => null,
                'employee' => $r->employee ? ['id' => $r->employee->id, 'name' => $r->employee->name] : null,
                'reason' => $r->reason,
                'receipt_url' => null,
                'charge_id' => null,
            ];
        }
        usort($transactions, static fn ($a, $b) => strcmp((string) ($a['paid_at'] ?? ''), (string) ($b['paid_at'] ?? '')));

        return [
            'lines' => $lines,
            'transactions' => $transactions,
            'charges' => $charges->map(fn (ContractCharge $c) => $this->chargeArray($c, $contract))->values()->all(),
            'invoice_number' => $invoice?->invoice_number,
            'invoice_url' => $withInvoiceUrl ? self::invoiceUrl($contract) : null,
            // دفعة (و) — D4: ملف PDF حقيقي للفاتورة (فقط بعد الدفع).
            'has_invoice' => $hasInvoice = ($originalPaid + $extraPaid) > 0.009 || (bool) $contract->is_completed,
            'invoice_pdf_url' => $withInvoiceUrl ? \App\Services\Invoices\InvoicePdfService::contractUrl($contract, $hasInvoice) : null,
            'totals' => [
                'original' => round($originalPaid, 2),
                'extra' => $extraPaid,
                'refunded' => $state['refunded_total'],
                'net' => round($originalPaid + $extraPaid - $state['refunded_total'], 2),
                'due' => $state['due_total'],
                'outstanding' => $state['outstanding'],
                'refund_due' => $state['refund_due'],
            ],
            'state' => $state,
        ];
    }

    /**
     * ملخص خفيف لصفوف القائمة (يعتمد على العلاقات المحمّلة مسبقاً، بلا حساب السعر الحي):
     * {status, status_label, method, method_label, label, paid_total, refunded_total, net_total,
     *  pending_charges_total, pending_charges_count, awaiting_charge}.
     *
     * @return array<string, mixed>
     */
    public function summaryForList(Contract $contract): array
    {
        $payments = collect();
        if ($contract->relationLoaded('contractPayments')) {
            $payments = $payments->merge($contract->contractPayments->filter(fn (Payment $p) => $p->status === 'success'));
        }
        if ($contract->relationLoaded('paymentRows')) {
            $payments = $payments->merge($contract->paymentRows->filter(fn (Payment $p) => $p->status === 'success'));
        }
        if (! $contract->relationLoaded('contractPayments') && ! $contract->relationLoaded('paymentRows')) {
            $payments = $this->successfulPayments($contract);
        }
        $payments = $payments->unique('id')->values();

        $charges = $contract->relationLoaded('charges') ? $contract->charges : $this->charges($contract);
        $pending = $charges->where('status', ContractCharge::STATUS_PENDING);
        $refunds = $contract->relationLoaded('refunds') ? $contract->refunds->where('status', Refund::STATUS_SUCCEEDED) : $this->refunds($contract)->where('status', Refund::STATUS_SUCCEEDED);

        $paidTotal = round((float) $payments->sum('amount'), 2);
        $refundedTotal = round((float) $refunds->sum('amount'), 2);
        $netTotal = round($paidTotal - $refundedTotal, 2);
        $pendingTotal = round((float) $pending->sum('amount'), 2);
        $outstanding = $pendingTotal;

        if ($paidTotal <= 0.009) {
            $status = self::STATUS_UNPAID;
        } elseif ($refundedTotal > 0.009) {
            $status = $refundedTotal + 0.009 >= $paidTotal ? self::STATUS_REFUNDED : self::STATUS_PARTIALLY_REFUNDED;
        } else {
            $status = $pending->isNotEmpty() ? self::STATUS_PARTIALLY_PAID : self::STATUS_PAID;
        }
        $method = $this->method($payments);
        $extraPaid = round((float) $payments->filter(fn (Payment $p) => ! empty($p->charge_id))->sum('amount'), 2);

        return [
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status],
            'method' => $method,
            'method_label' => $method !== null ? self::METHOD_LABELS[$method] : null,
            'label' => $this->label($status, $method, $paidTotal, $netTotal + $pendingTotal, $refundedTotal, $netTotal),
            'paid_total' => $paidTotal,
            'paid_original' => round($paidTotal - $extraPaid, 2),
            'paid_extra' => $extraPaid,
            'refunded_total' => $refundedTotal,
            'net_total' => $netTotal,
            'outstanding' => $outstanding,
            'pending_charges_count' => $pending->count(),
            'pending_charges_total' => $pendingTotal,
            'awaiting_charge' => $pending->isNotEmpty(),
            'awaiting_charge_label' => $pending->isNotEmpty() ? 'بانتظار دفع فرق · '.rtrim(rtrim(number_format($pendingTotal, 2, '.', ''), '0'), '.').' ر.س' : null,
        ];
    }

    /** رابط موقّع مؤقت لصفحة الفاتورة القابلة للطباعة (HTML). */
    public static function invoiceUrl(Contract $contract, int $days = 7): ?string
    {
        try {
            return CustomerLinks::temporarySignedRoute('v2.invoices.print', now()->addDays($days), ['contract' => $contract->getKey()]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function chargeArray(ContractCharge $charge, ?Contract $contract = null): array
    {
        $contract ??= $charge->contract;
        $pending = $charge->status === ContractCharge::STATUS_PENDING;

        return [
            'id' => $charge->id,
            'kind' => $charge->kind,
            'kind_label' => $charge->kindLabel(),
            'amount' => (float) $charge->amount,
            'message' => $charge->message,
            // QA-F APP-14: السبب الداخلي للموظف لا يخرج أبداً في ردود العميل (الموقع/التطبيق/الفاتورة).
            'internal_reason' => self::isStaffContext() ? $charge->internal_reason : null,
            'status' => $charge->status,
            'status_label' => $charge->statusLabel(),
            'payment_url' => $pending && $contract !== null ? CustomerLinks::route('v2.contracts.charges.pay', ['uuid' => (string) $contract->uuid, 'cid' => $charge->id]) : null,
            'payment_id' => $charge->payment_id,
            'paid_at' => $charge->paid_at?->toIso8601String(),
            'created_by' => $charge->created_by,
            'created_by_name' => $charge->creator?->name,
            'created_at' => $charge->created_at?->toIso8601String(),
            'cancelled_at' => $charge->cancelled_at?->toIso8601String(),
        ];
    }

    /**
     * هل الطلب الحالي من اللوحة (أو مهمة خلفية)؟ ردود العميل (/api/v2/*) لا تحمل الحقول الداخلية.
     */
    public static function isStaffContext(): bool
    {
        try {
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                return true;
            }
            $request = request();

            return $request->is('api/admin/*') || $request->is('admin/*');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * الدفعات الناجحة (أصلية + رسوم + حوالات).
     *
     * @return Collection<int, Payment>
     */
    public function successfulPayments(Contract $contract): Collection
    {
        return $this->allPayments($contract)->filter(fn (Payment $p) => $p->status === 'success')->values();
    }

    /** @return Collection<int, Payment> */
    public function allPayments(Contract $contract): Collection
    {
        if (! filled($contract->uuid) || ! SchemaCache::hasTable('payments')) {
            return collect();
        }

        return Payment::query()->forContract($contract)->with('employee:id,name')->orderBy('id')->get();
    }

    /** @return Collection<int, ContractCharge> */
    public function charges(Contract $contract): Collection
    {
        if (! SchemaCache::hasTable('contract_charges')) {
            return collect();
        }

        return ContractCharge::query()->where('contract_id', $contract->id)->with('creator:id,name')->orderBy('id')->get();
    }

    /** @return Collection<int, Refund> */
    public function refunds(Contract $contract): Collection
    {
        if (! SchemaCache::hasTable('refunds')) {
            return collect();
        }

        return Refund::query()->with('employee:id,name')
            ->where(fn ($q) => $q->where('contract_id', $contract->id)->orWhere('contract_uuid', (string) $contract->uuid))
            ->orderBy('id')->get();
    }

    public function refundedTotal(Contract $contract): float
    {
        return round((float) $this->refunds($contract)->where('status', Refund::STATUS_SUCCEEDED)->sum('amount'), 2);
    }

    public function originalDue(Contract $contract): float
    {
        try {
            return round((float) ContractPricing::total($contract), 2);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * مجموع الدفعات الأصلية الناجحة (غير المرتبطة برسم) — بديل السعر الحي حين يتعذّر حسابه.
     *
     * @param  Collection<int, Payment>  $payments
     */
    private function originalPaidSnapshot(Collection $payments): float
    {
        return round((float) $payments
            ->filter(fn (Payment $p) => empty($p->charge_id)
                && in_array($this->paymentKind($p), [Payment::KIND_ORIGINAL, Payment::KIND_BANK_TRANSFER], true))
            ->sum('amount'), 2);
    }

    public function paymentKind(Payment $p): string
    {
        $kind = (string) ($p->kind ?? '');
        if ($kind !== '') {
            return $kind;
        }

        return $p->payment_method === Payment::METHOD_BANK_TRANSFER ? Payment::KIND_BANK_TRANSFER : Payment::KIND_ORIGINAL;
    }

    /**
     * @return array<string, mixed>
     */
    public function transactionFromPayment(Payment $p, Contract $contract): array
    {
        $kind = $this->paymentKind($p);
        $method = $p->payment_method === Payment::METHOD_BANK_TRANSFER ? 'bank_transfer' : 'moyasar';
        $charge = $p->charge_id ? ContractCharge::query()->find($p->charge_id) : null;
        $paidAt = $p->payment_date ? Carbon::parse($p->payment_date) : $p->created_at;
        if ($paidAt && $p->created_at && $p->payment_date && ! str_contains((string) $p->payment_date, ':')) {
            $paidAt = $paidAt->copy()->setTimeFrom($p->created_at);
        }

        return [
            'id' => $p->id,
            'kind' => $kind,
            'kind_label' => match ($kind) {
                Payment::KIND_PRICE_DIFFERENCE => 'فرق سعر',
                Payment::KIND_EXTRA_FEE => 'رسوم إضافية',
                Payment::KIND_BANK_TRANSFER => 'حوالة بنكية',
                default => 'الدفعة الأصلية',
            },
            'amount' => (float) $p->amount,
            'method' => $method,
            'method_label' => self::METHOD_LABELS[$method],
            'brand' => $p->payment_brand,
            'status' => $p->status,
            'status_label' => match ($p->status) {
                'success' => 'ناجحة',
                'failed' => 'فاشلة',
                'pending' => 'قيد المراجعة',
                default => (string) $p->status,
            },
            'paid_at' => $paidAt?->toIso8601String(),
            'reference' => $p->reference ?: ($p->gateway_payment_id ?: (preg_match('/^[A-Za-z0-9_\-]+$/', (string) $p->name) ? (string) $p->name : null)),
            'card_last4' => null,
            'employee' => $p->employee ? ['id' => $p->employee->id, 'name' => $p->employee->name] : null,
            'reason' => $charge?->message ?: $p->note,
            'receipt_url' => $p->receipt_path ? self::receiptUrl($p) : null,
            'charge_id' => $p->charge_id,
            'refunded_amount' => (float) ($p->refunded_amount ?? 0),
            'refund_status' => $p->refund_status ?? null,
            'refundable_amount' => $p->status === 'success' ? max(0, round((float) $p->amount - (float) ($p->refunded_amount ?? 0), 2)) : 0.0,
        ];
    }

    /** رابط موقّع مؤقت لإيصال الحوالة (نفس آلية صور الصكوك). */
    public static function receiptUrl(Payment $payment, int $minutes = 30): ?string
    {
        if (! filled($payment->receipt_path)) {
            return null;
        }
        try {
            return CustomerLinks::temporarySignedRoute('v2.payments.receipt', now()->addMinutes($minutes), ['payment' => $payment->getKey()]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * بنود الأصل (B-3):
     *  - غير مدفوع ⇒ السعر الحي (المستحق).
     *  - مدفوع ولقطة الفاتورة محفوظة ⇒ بنود اللقطة (ما دُفع فعلاً، لا تتأثر بالتعديلات اللاحقة).
     *  - مدفوع بلا لقطة ⇒ السعر الحي إن طابق المدفوع الأصلي، وإلا بند واحد «الدفعة الأصلية».
     *  وفي كل حالة مدفوعة يُضاف بند تسوية إن اختلف مجموع البنود عن المدفوع الأصلي (حوالة بمبلغ مختلف مثلاً)
     *  حتى يبقى مجموع lines = totals.net دائماً.
     *
     * @return list<array<string, mixed>>
     */
    private function originalLines(Contract $contract, ?\App\Models\Invoice $invoice, float $originalPaid): array
    {
        if ($originalPaid <= 0.009) {
            return $this->pricingLines($contract);
        }

        $snapshot = $invoice && is_array($invoice->lines) && ! empty($invoice->lines['items'])
            ? $this->mapPricingItems((array) $invoice->lines['items'])
            : null;

        $lines = $snapshot ?? $this->pricingLines($contract);
        $sum = round((float) array_sum(array_map(static fn (array $l) => (float) $l['amount'], $lines)), 2);

        if ($snapshot === null && abs($sum - $originalPaid) > 0.01) {
            return [[
                'key' => 'original_payment',
                'label' => 'الدفعة الأصلية',
                'amount' => $originalPaid,
                'quantity' => 1,
                'kind' => 'fee',
            ]];
        }

        if (abs($sum - $originalPaid) > 0.01) {
            $lines[] = [
                'key' => 'payment_adjustment',
                'label' => 'تسوية — فرق مبلغ الدفعة عن بنود الفاتورة',
                'amount' => round($originalPaid - $sum, 2),
                'quantity' => 1,
                'kind' => 'adjustment',
            ];
        }

        return $lines;
    }

    /**
     * بنود السعر الحي بأنواعها (fee/document/meter/discount/vat).
     *
     * @return list<array<string, mixed>>
     */
    private function pricingLines(Contract $contract): array
    {
        try {
            $breakdown = app(ContractInvoiceService::class)->contractBreakdown($contract);
        } catch (\Throwable) {
            return [];
        }

        return $this->mapPricingItems($breakdown['items'] ?? []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items  بنود بصيغة ContractInvoiceService (key/description/amount/quantity)
     * @return list<array<string, mixed>>
     */
    private function mapPricingItems(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = (string) ($item['key'] ?? '');
            $lines[] = [
                'key' => $key,
                'label' => (string) ($item['description'] ?? ''),
                'amount' => round((float) ($item['amount'] ?? 0), 2),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'kind' => match (true) {
                    $key === 'fee' => 'fee',
                    $key === 'document_surcharge' => 'document',
                    str_ends_with($key, '_meter') => 'meter',
                    $key === 'coupon' => 'discount',
                    $key === 'vat' => 'vat',
                    default => 'fee',
                },
            ];
        }

        return $lines;
    }

    private function status(float $paid, float $refunded, float $outstanding): string
    {
        if ($paid <= 0.009) {
            return self::STATUS_UNPAID;
        }
        if ($refunded > 0.009) {
            return $refunded + 0.009 >= $paid ? self::STATUS_REFUNDED : self::STATUS_PARTIALLY_REFUNDED;
        }

        return $outstanding > 0.009 ? self::STATUS_PARTIALLY_PAID : self::STATUS_PAID;
    }

    /** @param Collection<int, Payment> $payments */
    private function method(Collection $payments): ?string
    {
        if ($payments->isEmpty()) {
            return null;
        }
        $methods = $payments->map(fn (Payment $p) => $p->payment_method === Payment::METHOD_BANK_TRANSFER ? 'bank_transfer' : 'moyasar')->unique()->values();

        return $methods->count() > 1 ? 'mixed' : (string) $methods->first();
    }

    private function label(string $status, ?string $method, float $paid, float $due, float $refunded, float $net): string
    {
        $m = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        $methodLabel = $method !== null ? self::METHOD_LABELS[$method] : null;

        return match ($status) {
            self::STATUS_UNPAID => 'غير مدفوع'.($due > 0 ? ' · '.$m($due).' ر.س' : ''),
            self::STATUS_PAID => 'مدفوع · '.$methodLabel.' · '.$m($paid).' ر.س',
            self::STATUS_PARTIALLY_PAID => 'مدفوع جزئياً · '.$m($net).' من '.$m($due).' ر.س',
            self::STATUS_PARTIALLY_REFUNDED => 'مسترجع جزئياً · '.$m($refunded).' من '.$m($paid).' ر.س',
            self::STATUS_REFUNDED => 'مسترجع · '.$m($refunded).' ر.س',
            default => $status,
        };
    }
}
