<?php

namespace App\Modules\Payments\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PaymentDataAdminResource;
use App\Http\Traits\Responser;
use App\Interfaces\PaymentGatewayInterface;
use App\Models\Payment;
use Illuminate\Http\Request;

class ContractPaymentController extends Controller
{
    use Responser;
    use \App\Http\Concerns\RedactsPublicPaymentPayload;

    public function __construct(
        protected PaymentGatewayInterface $paymentService
    ) {}

    /**
     * Get ClickPay redirect URL for a contract.
     * GET /api/admin/payment-gateway/{uuid}
     */
    public function paymentUrl(Request $request, string $uuid)
    {
        $response = $this->paymentService->createPaymentUrlResponse($uuid);

        // نقطة عامة (رابط الموظف للعميل): لا معرّف عقد ولا تفاصيل دفعة لطلب مدفوع. (DASHBOARD-7)
        $data = $response->getData(true);
        if (is_array($data) && ($data['already_paid'] ?? false) === true && ! ($data['test_mode'] ?? false)) {
            unset($data['contract_id'], $data['payment']);
            $response->setData($data);
        }

        return $response;
    }

    /**
     * List payment records for a contract uuid.
     * GET /api/admin/payment-gateway/{uuid}/payments
     */
    public function paymentsByContract(string $uuid)
    {
        $payments = Payment::query()
            ->with(['contract.user'])
            ->matchingContractUuid($uuid)
            ->latest('id')
            ->get();

        return $this->apiResponse(
            PaymentDataAdminResource::collection($payments),
            trans('api.success')
        );
    }

    /**
     * ClickPay IPN callback.
     * POST /api/admin/payment-gateway/status/{uuid}/success
     */
    public function updateCartByIPN(Request $request, string $uuid)
    {
        $this->paymentService->processIpn($request, $uuid);
    }

    /**
     * ClickPay return callback.
     * POST /api/admin/payment-gateway/status/{uuid}
     */
    public function callback(Request $request, string $uuid)
    {
        $this->paymentService->processIpn($request, $uuid);

        $payload = $this->paymentService->paymentStatusPayload(
            $uuid,
            'return',
            $request->input('id') ?? $request->input('payment_id'),
            $request->input('invoice_id')
        );

        if (! $this->gatewayReturnVerified($request, $payload)) {
            $payload = $this->minimalPaymentStatus($payload);
        }

        return response()->json($payload);
    }

    /**
     * GET /api/admin/payment-gateway/status/success/{uuid}
     */
    public function success(Request $request, string $uuid)
    {
        return $this->redirectByPaymentState($request, $uuid);
    }

    /**
     * GET /api/admin/payment-gateway/status/error/{uuid}
     */
    public function error(Request $request, string $uuid)
    {
        return $this->redirectByPaymentState($request, $uuid);
    }

    private function redirectByPaymentState(Request $request, string $uuid)
    {
        try {
            $this->paymentService->processIpn($request, $uuid);
        } catch (\Throwable) {
            // Resolve from stored/gateway state below.
        }

        $paid = $this->paymentService->isPaymentConfirmed(
            $uuid,
            $request->input('id') ?? $request->input('payment_id'),
            $request->input('invoice_id')
        );

        return redirect()->away($this->frontendPaymentRedirectUrl($paid ? 'success' : 'error', $uuid));
    }

    private function frontendPaymentRedirectUrl(string $type, string $uuid): string
    {
        $templateKey = $type === 'error'
            ? 'services.moyasar.payment_error_url_template'
            : 'services.moyasar.payment_success_url_template';

        $template = (string) config($templateKey, '');
        if ($template !== '') {
            $url = str_replace('{uuid}', $uuid, $template);
        } else {
            $base = rtrim((string) config('services.moyasar.payment_frontend_url', 'http://localhost:3000'), '/');
            $path = $type === 'error' ? 'error' : 'success';
            $url = "{$base}/payment/{$path}/{$uuid}";
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'status='.($type === 'error' ? 'failed' : 'success');
    }
}
