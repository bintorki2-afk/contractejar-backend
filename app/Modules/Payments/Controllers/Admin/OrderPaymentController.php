<?php

namespace App\Modules\Payments\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\Responser;
use App\Models\Contract;
use App\Models\ContractCharge;
use App\Models\Employee;
use App\Services\Charges\ChargeService;
use App\Services\Payments\BankTransferService;
use App\Services\Payments\ContractPaymentState;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * دفعة (هـ) — 2.2 / 2.3: الحوالة البنكية والرسوم (فرق سعر / رسوم إضافية) على الطلب.
 */
class OrderPaymentController extends Controller
{
    use Responser;

    public function __construct(
        private readonly BankTransferService $bankTransfers,
        private readonly ChargeService $charges,
        private readonly ContractPaymentState $paymentState,
    ) {}

    /** GET /api/admin/orders/{id}/payment-state */
    public function state(int $id)
    {
        $contract = $this->contract($id);

        return $this->apiResponse([
            'payment_state' => $this->paymentState->state($contract),
            'payment_details' => $this->paymentState->details($contract),
        ], trans('api.success'));
    }

    /**
     * POST /api/admin/orders/{id}/payments/bank-transfer (multipart)
     * { amount, receipt (image/pdf ≤4MB), reference?, paid_at?, note?, charge_id? } — permission payments.record_transfer
     */
    public function bankTransfer(Request $request, int $id)
    {
        $employee = $request->user();
        if (! $employee instanceof Employee) {
            return $this->errorMessage(trans('api.unauthorized'), 403);
        }

        try {
            $data = $request->validate([
                'amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
                'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.BankTransferService::MAX_KB],
                'reference' => ['nullable', 'string', 'max:150'],
                'paid_at' => ['nullable', 'string', 'max:40'],
                'note' => ['nullable', 'string', 'max:1000'],
                'charge_id' => ['nullable', 'integer'],
            ], [
                'receipt.required' => 'صورة الإيصال مطلوبة.',
                'receipt.mimes' => 'الإيصال يجب أن يكون صورة (jpg/png/webp) أو PDF.',
                'receipt.max' => 'حجم الإيصال يجب ألا يتجاوز 4 ميجابايت.',
                'amount.required' => 'المبلغ مطلوب.',
            ]);

            $contract = $this->contract($id);
            $result = $this->bankTransfers->record(
                $contract,
                (float) $data['amount'],
                $request->file('receipt'),
                $employee,
                $data['reference'] ?? null,
                $data['paid_at'] ?? null,
                $data['note'] ?? null,
                isset($data['charge_id']) ? (int) $data['charge_id'] : null,
            );

            return $this->apiResponse([
                'payment_state' => $result['payment_state'],
                'transaction' => $result['transaction'],
                'payment_details' => $this->paymentState->details($contract->fresh()),
                'charge' => $result['charge'] ? $this->paymentState->chargeArray($result['charge'], $contract) : null,
                'contract' => $this->contractSummary($contract->fresh()),
            ], 'تم تسجيل الحوالة البنكية.');
        } catch (ValidationException $e) {
            return $this->validation($e);
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.contract_not_found'), 404);
        }
    }

    /**
     * GET /api/admin/orders/{id}/bank-transfer-message?amount=&charge_id= — رسالة تعليمات الحوالة (قالب bank_transfer_instructions)
     * بالمبلغ المتبقي افتراضياً (أو مبلغ رسم معلّق) + wa.me. الآيبان من الإعدادات؛ لا يظهر للعميل في الموقع/التطبيق.
     */
    public function bankTransferMessage(Request $request, int $id)
    {
        $contract = $this->contract($id);
        $state = $this->paymentState->state($contract);
        $amount = (float) $state['outstanding'];
        if ($request->filled('charge_id')) {
            $charge = ContractCharge::query()->where('contract_id', $contract->id)->find((int) $request->input('charge_id'));
            if ($charge !== null) {
                $amount = (float) $charge->amount;
            }
        }
        if ($request->filled('amount') && is_numeric($request->input('amount'))) {
            $amount = (float) $request->input('amount');
        }
        if ($amount <= 0) {
            $amount = (float) $state['due_total'];
        }

        $templates = app(\App\Services\MessageTemplateService::class);
        $bank = $templates->bankVars();
        $vars = $templates->varsFor($contract, ['amount' => rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.')]);
        $rendered = $templates->render('bank_transfer_instructions', 'whatsapp', $vars, "لإتمام طلبك رقم {order} حوّل {amount} ر.س إلى: {bank} — {iban} — {account_name}");
        $message = (string) ($rendered['body'] ?? '');
        $phone = app(\App\Services\Orders\OrderStageService::class)->customerPhone($contract);

        return $this->apiResponse([
            'amount' => round($amount, 2),
            'message' => $message,
            'phone' => $phone,
            'whatsapp_url' => $phone !== null ? 'https://wa.me/'.$phone.'?text='.rawurlencode($message) : null,
            'bank' => array_merge($bank, ['is_configured' => $bank['iban'] !== '']),
            'payment_state' => $state,
        ], trans('api.success'));
    }

    /** GET /api/admin/orders/{id}/charges */
    public function index(int $id)
    {
        $contract = $this->contract($id);

        return $this->apiResponse([
            'items' => $this->charges->forAdmin($contract),
            'payment_state' => $this->paymentState->state($contract),
        ], trans('api.success'));
    }

    /** POST /api/admin/orders/{id}/charges { amount, message, internal_reason? } — permission payments.add_fee */
    public function store(Request $request, int $id)
    {
        $employee = $request->user();
        if (! $employee instanceof Employee) {
            return $this->errorMessage(trans('api.unauthorized'), 403);
        }

        try {
            $data = $request->validate([
                'amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
                'message' => ['required', 'string', 'min:3', 'max:1000'],
                'internal_reason' => ['nullable', 'string', 'max:1000'],
            ], [
                'message.required' => 'اكتب رسالة واضحة للعميل — سيقرأها كما هي.',
                'amount.required' => 'المبلغ مطلوب.',
            ]);
            $contract = $this->contract($id);
            $charge = $this->charges->addExtraFee($contract, (float) $data['amount'], (string) $data['message'], $employee, $data['internal_reason'] ?? null);

            return $this->apiResponse([
                'charge' => $this->paymentState->chargeArray($charge->fresh(['creator']), $contract),
                'payment_state' => $this->paymentState->state($contract->fresh()),
            ], 'تمت إضافة الرسوم — ولّد رابط الدفع أو سجّل حوالة.', true, 201);
        } catch (ValidationException $e) {
            return $this->validation($e);
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.contract_not_found'), 404);
        }
    }

    /** POST /api/admin/orders/{id}/charges/{cid}/payment-link */
    public function paymentLink(Request $request, int $id, int $cid)
    {
        $employee = $request->user();
        if (! $employee instanceof Employee) {
            return $this->errorMessage(trans('api.unauthorized'), 403);
        }

        try {
            $contract = $this->contract($id);
            $charge = $this->charge($contract, $cid);
            $client = strtolower((string) ($request->input('client') ?: $request->header('X-Client') ?: 'web'));
            $result = $this->charges->paymentLink($contract, $charge, $employee, $client);

            return $this->apiResponse(array_merge($result, [
                'payment_state' => $this->paymentState->state($contract->fresh()),
            ]), trans('api.success'));
        } catch (ValidationException $e) {
            return $this->validation($e);
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.contract_not_found'), 404);
        } catch (\RuntimeException $e) {
            $unavailable = (int) $e->getCode() >= 500 || (int) $e->getCode() === 0;

            return $this->errorMessage($unavailable ? trans('api.payment_gateway_unavailable') : trans('api.not_accept'), $unavailable ? 503 : 400);
        }
    }

    /** POST /api/admin/orders/{id}/charges/{cid}/cancel */
    public function cancel(Request $request, int $id, int $cid)
    {
        try {
            $contract = $this->contract($id);
            $charge = $this->charges->cancel($contract, $this->charge($contract, $cid), $request->user() instanceof Employee ? $request->user() : null);

            return $this->apiResponse([
                'charge' => $this->paymentState->chargeArray($charge, $contract),
                'payment_state' => $this->paymentState->state($contract->fresh()),
            ], 'أُلغيت الرسوم.');
        } catch (ValidationException $e) {
            return $this->validation($e);
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.contract_not_found'), 404);
        }
    }

    private function contract(int $id): Contract
    {
        return Contract::query()->where('is_delete', 0)->findOrFail($id);
    }

    private function charge(Contract $contract, int $cid): ContractCharge
    {
        return ContractCharge::query()->where('contract_id', $contract->id)->findOrFail($cid);
    }

    /** @return array<string, mixed> */
    private function contractSummary(Contract $contract): array
    {
        $status = \App\Support\ContractFrontendStatus::for($contract);

        return [
            'id' => $contract->id,
            'uuid' => (string) $contract->uuid,
            'is_completed' => (bool) $contract->is_completed,
            'contract_status_id' => $contract->contract_status_id,
            'status' => $status['status'],
            'status_label' => $status['status_label'],
            'status_key' => \App\Models\ContractStatus::keyForId($contract->contract_status_id ? (int) $contract->contract_status_id : null),
        ];
    }

    private function validation(ValidationException $e)
    {
        return response()->json([
            'message' => collect($e->errors())->flatten()->first() ?? $e->getMessage(),
            'errors' => $e->errors(),
            'code' => 422,
            'success' => false,
        ], 422);
    }
}
