<?php

namespace App\Modules\Payments\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Traits\Responser;
use App\Models\Contract;
use App\Models\ContractCharge;
use App\Models\Payment;
use App\Services\Charges\ChargeService;
use App\Services\ContractInvoiceService;
use App\Services\Payments\BankTransferService;
use App\Services\Payments\ContractPaymentState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * دفعة (هـ) — العميل: دفع رسوم معلّقة، الفاتورة القابلة للطباعة (رابط موقّع)، إيصال الحوالة (رابط موقّع).
 */
class ChargePaymentController extends Controller
{
    use Responser;

    /**
     * GET /api/v2/contracts/{uuid}/charges/{cid}/pay — رابط Moyasar لرسم العميل المعلّق (جلسة زائر أو مستخدم).
     */
    public function pay(Request $request, string $uuid, int $cid, ChargeService $charges, ContractPaymentState $state)
    {
        $contract = Contract::query()->where('is_delete', 0)
            ->where(fn ($q) => $q->where('uuid', $uuid)->when(ctype_digit($uuid), fn ($w) => $w->orWhere('id', (int) $uuid)))
            ->first();
        if ($contract === null || (int) $contract->user_id !== (int) auth()->id()) {
            return $this->errorMessage(trans('api.contract_not_found'), 404);
        }
        $charge = ContractCharge::query()->where('contract_id', $contract->id)->find($cid);
        if ($charge === null) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        try {
            $client = strtolower((string) ($request->query('platform') ?: $request->query('client') ?: $request->header('X-Client') ?: 'web'));
            $result = $charges->customerPaymentLink($contract, $charge, in_array($client, ['app', 'ios', 'android', 'mobile'], true) ? 'app' : 'web');

            return $this->apiResponse(array_merge($result, [
                'contract_uuid' => (string) $contract->uuid,
                'amount' => (float) $charge->amount,
                'message' => $charge->message,
                'payment_state' => $state->state($contract->fresh()),
            ]), trans('api.success'));
        } catch (ValidationException $e) {
            return $this->errorMessage(collect($e->errors())->flatten()->first() ?? $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $unavailable = (int) $e->getCode() >= 500 || (int) $e->getCode() === 0;

            return $this->errorMessage($unavailable ? trans('api.payment_gateway_unavailable') : trans('api.not_accept'), $unavailable ? 503 : 400);
        }
    }

    /**
     * GET /api/v2/invoices/print/{contract} — فاتورة تراكمية قابلة للطباعة (HTML)، محمية بتوقيع مؤقت.
     */
    public function print(Contract $contract, ContractInvoiceService $invoices)
    {
        $payload = $invoices->forContract($contract, persist: (bool) $contract->is_completed);

        return response()->view('invoice-print', ['invoice' => $payload], 200)
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex');
    }

    /**
     * دفعة (و) — D4: GET /api/v2/invoices/pdf/{contract} — ملف PDF للفاتورة (رابط موقّع مؤقت).
     * ?download=1 ⇒ تنزيل، وإلا عرض داخل المتصفح.
     */
    public function pdf(Request $request, Contract $contract, \App\Services\Invoices\InvoicePdfService $pdf)
    {
        $file = $pdf->forContract($contract);

        return self::pdfResponse($file['content'], $file['filename'], $request->boolean('download'));
    }

    public static function pdfResponse(string $content, string $filename, bool $download)
    {
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * GET /api/v2/payments/{payment}/receipt — إيصال الحوالة (رابط موقّع مؤقت).
     */
    public function receipt(Payment $payment)
    {
        $resolved = BankTransferService::resolveReceipt($payment->receipt_path);
        abort_if($resolved === null, 404);
        [$disk, $path] = $resolved;

        return Storage::disk($disk)->response($path);
    }
}
