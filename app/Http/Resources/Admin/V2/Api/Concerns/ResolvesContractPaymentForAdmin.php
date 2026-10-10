<?php

namespace App\Http\Resources\Admin\V2\Api\Concerns;

use App\Models\Payment;

trait ResolvesContractPaymentForAdmin
{
    /**
     * Admin orders list: amount from payments where status = success only.
     *
     * @return array{
     *     is_paid: bool,
     *     payment_status: 'paid'|'unpaid',
     *     payment_label_ar: string,
     *     amount_payment: float|string
     * }
     */
    protected function contractPaymentFields(): array
    {
        // QA-F C6: «مدفوع» من حالة الدفع الفعلية (نفس مصدر شارة الدفع payment_state) لا من is_completed.
        $isPaid = $this->resolveIsPaidFromPaymentState();
        $successPayment = $this->resolveSuccessfulPayment();

        $amount = $this->resolvePaymentAmountFromPayments($successPayment);

        return [
            'is_paid' => $isPaid,
            'payment_status' => $isPaid ? 'paid' : 'unpaid',
            'payment_label_ar' => $isPaid ? 'تم الدفع' : 'لم يتم الدفع',
            'amount_payment' => $isPaid
                ? ($amount !== null && $amount !== '' ? round((float) $amount, 2) : 'تم الدفع')
                : 'لم يتم الدفع',
        ];
    }

    private function resolveIsPaidFromPaymentState(): bool
    {
        try {
            $summary = app(\App\Services\Payments\ContractPaymentState::class)->summaryForList($this->resource);

            return in_array($summary['status'] ?? null, [
                \App\Services\Payments\ContractPaymentState::STATUS_PAID,
                \App\Services\Payments\ContractPaymentState::STATUS_PARTIALLY_PAID,
                \App\Services\Payments\ContractPaymentState::STATUS_PARTIALLY_REFUNDED,
            ], true);
        } catch (\Throwable) {
            return (bool) $this->is_completed;
        }
    }

    private function resolvePaymentAmountFromPayments(?Payment $successPayment): mixed
    {
        if (isset($this->successful_payment_amount) && $this->successful_payment_amount !== null && $this->successful_payment_amount !== '') {
            return $this->successful_payment_amount;
        }

        return $successPayment?->amount;
    }

    private function resolveSuccessfulPayment(): ?Payment
    {
        if ($this->relationLoaded('contractPayments')) {
            // القوائم تحمّل الدفعات الناجحة مسبقاً (+ المبلغ عبر successful_payment_amount):
            // لا استعلام إضافي لكل صف غير مدفوع (كان N+1 في قائمة الطلبات).
            return $this->contractPayments
                // الدفعات الناجحة فقط — مورد التفاصيل يحمّل كل الدفعات (فاشلة أيضاً) للسجل. (DASHBOARD-9)
                ->filter(fn (Payment $payment) => $payment->status === 'success'
                    && $this->paymentContractUuidMatches($payment->contract_uuid))
                ->sortByDesc('id')
                ->first();
        }

        return Payment::query()
            ->successfulMatchingContractUuid($this->uuid)
            ->latest('id')
            ->first();
    }

    private function paymentContractUuidMatches(?string $paymentContractUuid): bool
    {
        if ($paymentContractUuid === null || $paymentContractUuid === '') {
            return false;
        }

        $uuid = (string) $this->uuid;

        return $paymentContractUuid === $uuid
            || str_starts_with($paymentContractUuid, $uuid.'-');
    }
}
