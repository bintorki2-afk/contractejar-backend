<?php

namespace App\Services\Payments;

use App\Models\Contract;
use App\Models\ContractCharge;
use App\Models\Employee;
use App\Models\Payment;
use App\Services\Charges\ChargeService;
use App\Services\CustomerNotificationService;
use App\Services\MoyasarPaymentService;
use App\Services\Orders\OrderFlowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * تسجيل حوالة بنكية من الموظف (دفعة هـ — E2): مبلغ + صورة الإيصال (+ مرجع/تاريخ/ملاحظة اختيارية).
 * الإيصال يُحفظ على القرص الخاص (Volume) ويُقرأ عبر رابط موقّع مؤقت فقط — مثل صور الصكوك.
 */
class BankTransferService
{
    public const DISK = 'local';

    public const DIR = 'payments/receipts';

    public const MAX_KB = 4096;

    public function __construct(
        private readonly OrderFlowService $flow,
        private readonly CustomerNotificationService $customers,
        private readonly ContractPaymentState $paymentState,
    ) {}

    /**
     * @return array{payment: Payment, transaction: array<string, mixed>, payment_state: array<string, mixed>, charge: ContractCharge|null}
     *
     * @throws ValidationException
     */
    public function record(Contract $contract, float $amount, UploadedFile $receipt, Employee $employee, ?string $reference = null, ?string $paidAt = null, ?string $note = null, ?int $chargeId = null): array
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['المبلغ يجب أن يكون أكبر من صفر.']]);
        }

        $charge = null;
        if ($chargeId !== null) {
            $charge = ContractCharge::query()->where('contract_id', $contract->id)->find($chargeId);
            if ($charge === null) {
                throw ValidationException::withMessages(['charge_id' => ['الرسوم غير موجودة لهذا الطلب.']]);
            }
            if (! $charge->isPending()) {
                throw ValidationException::withMessages(['charge_id' => ['الرسوم ليست معلّقة.']]);
            }
        } else {
            $state = $this->paymentState->state($contract);
            if ($state['status'] === ContractPaymentState::STATUS_PAID && $state['outstanding'] <= 0.009) {
                throw ValidationException::withMessages(['amount' => ['الطلب مدفوع بالكامل مسبقاً — اختر رسوماً معلّقة أو استخدم الاسترجاع.']]);
            }
        }

        $date = null;
        if (filled($paidAt)) {
            try {
                $date = Carbon::parse((string) $paidAt);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['paid_at' => ['تاريخ الحوالة غير صالح.']]);
            }
        }

        $path = $receipt->store(self::DIR.'/'.$contract->id, self::DISK);
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['receipt' => ['تعذّر حفظ صورة الإيصال.']]);
        }

        $payment = Payment::query()->create([
            'name' => 'Bank transfer '.$contract->uuid.($charge ? ' charge '.$charge->id : ''),
            'amount' => round($amount, 2),
            'contract_uuid' => $charge ? $charge->paymentKey() : (string) $contract->uuid,
            'contract_id' => $contract->id,
            'charge_id' => $charge?->id,
            'kind' => $charge ? $charge->kind : Payment::KIND_BANK_TRANSFER,
            'tran_currency' => 'SAR',
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'payment_brand' => 'bank',
            'status' => 'success',
            'payment_date' => ($date ?? now())->toDateTimeString(),
            'employee_id' => $employee->id,
            'receipt_path' => $path,
            'reference' => filled($reference) ? trim((string) $reference) : null,
            'note' => filled($note) ? trim((string) $note) : null,
        ]);

        if ($charge !== null) {
            app(ChargeService::class)->settleWithPayment($charge, $payment, 'bank_transfer', $employee);
        } else {
            // الدفعة الأصلية عبر حوالة ⇒ نفس انعكاسات دفع Moyasar (is_completed، قيد المراجعة، الفاتورة، الإشعارات).
            $this->flow->activity($contract, 'bank_transfer_recorded', $employee, null, [
                'payment_id' => $payment->id, 'amount' => (float) $payment->amount, 'reference' => $payment->reference, 'paid_at' => $payment->payment_date,
            ], 'employee', 'تسجيل حوالة بنكية '.rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.').' ر.س'.($payment->reference ? ' — مرجع '.$payment->reference : ''));

            try {
                app(MoyasarPaymentService::class)->settleRecordedPayment((string) $contract->uuid);
            } catch (\Throwable $e) {
                Log::warning('bank transfer settle failed', ['contract_id' => $contract->id, 'error' => $e->getMessage()]);
            }

            try {
                app(\App\Services\ContractInvoiceService::class)->forContract($contract->fresh());
            } catch (\Throwable $e) {
                Log::warning('invoice after bank transfer failed', ['contract_id' => $contract->id, 'error' => $e->getMessage()]);
            }

            try {
                $this->customers->bankTransferRecorded($contract->fresh(['user']), (float) $payment->amount);
            } catch (\Throwable $e) {
                Log::warning('bank transfer notification failed', ['contract_id' => $contract->id, 'error' => $e->getMessage()]);
            }
        }

        $contract->refresh();
        $payment = $payment->fresh(['employee']);

        return [
            'payment' => $payment,
            'transaction' => $this->paymentState->transactionFromPayment($payment, $contract),
            'payment_state' => $this->paymentState->state($contract),
            'charge' => $charge?->fresh(),
        ];
    }

    /** @return array{0: string, 1: string}|null [disk, path] */
    public static function resolveReceipt(?string $raw): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $path = ltrim(trim($raw), '/');
        if (Storage::disk(self::DISK)->exists($path)) {
            return [self::DISK, $path];
        }

        return null;
    }
}
