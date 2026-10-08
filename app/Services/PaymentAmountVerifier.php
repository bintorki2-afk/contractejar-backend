<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractPaidByEmployee;
use App\Models\LessorChangeRequest;
use App\Support\ContractPricing;

/**
 * فحص (CROSS-8): قبل اعتماد دفعة «paid» من البوابة نتحقق أن المبلغ (بالهللات) والعملة
 * يطابقان المستحق على الطلب: رابط دفع الموظف → مبلغه، طلب تغيير المؤجر → رسومه،
 * العقد → ContractPricing::total. عدم المطابقة ⇒ لا يُعتمد الدفع (يُسجَّل للمراجعة).
 */
class PaymentAmountVerifier
{
    /** فرق مسموح بالهللات (تقريب). */
    public const TOLERANCE_MINOR = 1;

    /**
     * @param  array<string, mixed>  $gatewayPayment
     * @return array{ok: bool, reason?: string, expected_minor?: int|null, paid_minor?: int, currency?: string}
     */
    public function check(array $gatewayPayment, string $uuid): array
    {
        $expectedCurrency = strtoupper((string) config('services.moyasar.currency', 'SAR'));
        $currency = strtoupper((string) ($gatewayPayment['currency'] ?? $expectedCurrency));
        $paidMinor = (int) round((float) ($gatewayPayment['amount'] ?? 0));

        if ($currency !== $expectedCurrency) {
            return ['ok' => false, 'reason' => 'currency_mismatch', 'paid_minor' => $paidMinor, 'currency' => $currency];
        }

        $expectedMinor = $this->expectedMinor($uuid);

        if ($expectedMinor === null || $expectedMinor <= 0) {
            return ['ok' => false, 'reason' => 'expected_amount_unknown', 'expected_minor' => $expectedMinor, 'paid_minor' => $paidMinor, 'currency' => $currency];
        }

        if (abs($paidMinor - $expectedMinor) > self::TOLERANCE_MINOR) {
            return ['ok' => false, 'reason' => 'amount_mismatch', 'expected_minor' => $expectedMinor, 'paid_minor' => $paidMinor, 'currency' => $currency];
        }

        return ['ok' => true, 'expected_minor' => $expectedMinor, 'paid_minor' => $paidMinor, 'currency' => $currency];
    }

    public function expectedMinor(string $uuid): ?int
    {
        $employeePaid = ContractPaidByEmployee::query()->where('contract_uuid', $uuid)->first();
        if ($employeePaid !== null) {
            return (int) round((float) $employeePaid->amount * 100);
        }

        $contract = Contract::query()->where('uuid', $uuid)->first();
        if ($contract !== null) {
            $amount = $this->expectedForContract($contract);

            return $amount === null ? null : (int) round($amount * 100);
        }

        try {
            $lessor = LessorChangeRequest::findByUuid($uuid);
        } catch (\Throwable) {
            $lessor = null;
        }
        if ($lessor !== null) {
            return (int) round((float) $lessor->fee * 100);
        }

        return null;
    }

    protected function expectedForContract(Contract $contract): ?float
    {
        try {
            return (float) ContractPricing::total($contract);
        } catch (\Throwable) {
            return null;
        }
    }
}
