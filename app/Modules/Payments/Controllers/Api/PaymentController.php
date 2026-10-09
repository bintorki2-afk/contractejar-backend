<?php

namespace App\Modules\Payments\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V2\PaymentMessageResource;
use App\Http\Traits\Responser;
use App\Interfaces\PaymentGatewayInterface;
use App\Models\PaymentMessage;
use App\Services\TaqnyatSmsService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    use Responser;
    use \App\Http\Concerns\RedactsPublicPaymentPayload;

    public function __construct(
        protected PaymentGatewayInterface $paymentService
    ) {}

    public function index(Request $request, $uuid)
    {
        $response = $this->paymentService->createPaymentUrlResponse(
            (string) $uuid,
            $this->resolvePaymentClient($request)
        );

        // طلب مدفوع: لا نكشف معرّف العقد وتفاصيل الدفعة إلا لصاحب الطلب. (DASHBOARD-7/APP-4)
        $data = $response->getData(true);
        if (is_array($data) && ($data['already_paid'] ?? false) === true
            && ! ($data['test_mode'] ?? false)
            && ! $this->requesterOwnsPaymentUuid($request, (string) $uuid)) {
            unset($data['contract_id'], $data['payment']);
            $response->setData($data);
        }

        return $response;
    }

    /**
     * Detect web vs mobile without requiring app changes.
     * Web: Origin/Referer matching PAYMENT_FRONTEND_URL, or platform=web.
     * Default is "app" (uses PAYMENT_APP_* deep links when set; otherwise same
     * backend→frontend success/error redirect as web).
     */
    private function resolvePaymentClient(Request $request): string
    {
        $explicit = strtolower((string) (
            $request->query('platform')
            ?? $request->query('client')
            ?? $request->header('X-Client')
            ?? $request->header('X-Platform')
            ?? ''
        ));

        if (in_array($explicit, ['app', 'mobile', 'ios', 'android'], true)) {
            return 'app';
        }

        if (in_array($explicit, ['web', 'frontend'], true)) {
            return 'web';
        }

        if ($this->requestLooksLikeWebFrontend($request)) {
            return 'web';
        }

        return 'app';
    }

    private function requestLooksLikeWebFrontend(Request $request): bool
    {
        $frontendBase = rtrim((string) config('services.moyasar.payment_frontend_url', ''), '/');
        $hosts = [];

        if ($frontendBase !== '') {
            $frontendHost = parse_url($frontendBase, PHP_URL_HOST);
            if (is_string($frontendHost) && $frontendHost !== '') {
                $hosts[] = strtolower($frontendHost);
            }
        }

        // Always treat the known AQDI frontend host as web.
        $hosts[] = 'aqdi-front-end.vercel.app';
        $hosts = array_values(array_unique($hosts));

        foreach ([$request->headers->get('Origin'), $request->headers->get('Referer')] as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            $host = parse_url($url, PHP_URL_HOST);
            if (! is_string($host) || $host === '') {
                continue;
            }

            if (in_array(strtolower($host), $hosts, true)) {
                return true;
            }
        }

        return false;
    }

    public function updateCartByIPN(Request $requestData, $uuid)
    {
        $this->paymentService->processIpn($requestData, (string) $uuid);
    }

    public function Callback(Request $request, $uuid)
    {
        $this->paymentService->processIpn($request, (string) $uuid);

        $payload = $this->paymentService->paymentStatusPayload(
            (string) $uuid,
            'return',
            $request->input('id') ?? $request->input('payment_id'),
            $request->input('invoice_id')
        );

        // نقطة عامة: التفاصيل (معرّفات/مبالغ/بيانات تغيير المؤجر) لصاحب الطلب أو لعودة بوابة موثّقة فقط.
        if (! $this->requesterOwnsPaymentUuid($request, (string) $uuid) && ! $this->gatewayReturnVerified($request, $payload)) {
            $payload = $this->minimalPaymentStatus($payload);
        }

        return response()->json($payload);
    }

    /**
     * Single entry from Moyasar: decide success vs failed from real payment state.
     * GET /api/status/result/{uuid}
     */
    public function result(Request $request, $uuid)
    {
        return $this->redirectByPaymentState($request, (string) $uuid);
    }

    public function success(Request $request, $uuid)
    {
        return $this->redirectByPaymentState($request, (string) $uuid);
    }

    public function error(Request $request, $uuid)
    {
        return $this->redirectByPaymentState($request, (string) $uuid);
    }

    /**
     * Public status for the frontend success/failed screens.
     * GET /api/payment/result/{uuid}
     * Also: GET /api/v2/payment/result/{uuid}
     *
     * Frontend must:
     * - if paid/screen=success → success UI + payment-content?type=success only
     * - if path is /payment/success/* → treat as success even before API returns
     */
    public function paymentResult(Request $request, $uuid)
    {
        $uuid = (string) $uuid;

        try {
            $this->paymentService->processIpn($request, $uuid);
        } catch (\Throwable) {
            // Best-effort; still resolve from local DB / gateway sync below.
        }

        try {
            $payload = $this->paymentService->paymentStatusPayload(
                $uuid,
                'return',
                $request->query('id')
                    ?? $request->input('id')
                    ?? $request->input('payment_id'),
                $request->query('invoice_id')
                    ?? $request->input('invoice_id')
            );
        } catch (\Throwable) {
            $paid = $this->paymentService->isPaymentConfirmed($uuid);
            $type = $paid ? 'success' : 'failed';
            $message = PaymentMessage::query()->where('type', $type)->first();

            return $this->apiResponse([
                'paid' => $paid,
                'status' => $paid ? 'success' : 'failed',
                'screen' => $paid ? 'success' : 'error',
                'contract_uuid' => $uuid,
                'contract_id' => null,
                'is_completed' => false,
                'payment' => null,
                'content' => $message ? (new PaymentMessageResource($message))->resolve() : null,
                'message_type' => $type,
                'frontend_url' => $this->frontendPaymentRedirectUrl($paid ? 'success' : 'error', $uuid),
            ], trans('api.success'));
        }

        // Trust gateway/local success only — never ?status=paid from the URL alone.
        $paid = (bool) ($payload['payment_confirmed'] ?? false);

        // معرّف العقد وتفاصيل الدفعة لصاحب الطلب أو لعودة بوابة موثّقة فقط. (APP-4)
        $detailed = $this->requesterOwnsPaymentUuid($request, $uuid) || $this->gatewayReturnVerified($request, $payload);

        $type = $paid ? 'success' : 'failed';
        $message = PaymentMessage::query()->where('type', $type)->first();

        return $this->apiResponse([
            'paid' => $paid,
            'status' => $paid ? 'success' : 'failed',
            'screen' => $paid ? 'success' : 'error',
            'contract_uuid' => $uuid,
            'contract_id' => $detailed ? ($payload['contract_id'] ?? null) : null,
            'is_completed' => (bool) ($payload['is_completed'] ?? false),
            'payment' => $detailed ? ($payload['payment'] ?? null) : null,
            'content' => $message ? (new PaymentMessageResource($message))->resolve() : null,
            'message_type' => $type,
            'frontend_url' => $this->frontendPaymentRedirectUrl($paid ? 'success' : 'error', $uuid),
        ], trans('api.success'));
    }

    private function redirectByPaymentState(Request $request, string $uuid)
    {
        try {
            $this->paymentService->processIpn($request, $uuid);
        } catch (\Throwable) {
            // Continue and resolve from stored/gateway state.
        }

        $gatewayId = $request->input('id')
            ?? $request->input('payment_id')
            ?? $request->query('id');
        $invoiceId = $request->input('invoice_id') ?? $request->query('invoice_id');

        $paid = $this->paymentService->isPaymentConfirmed($uuid, $gatewayId, $invoiceId);
        $result = $paid ? 'success' : 'error';
        $frontendUrl = $this->frontendPaymentRedirectUrl($result, $uuid, [
            'id' => $gatewayId,
            'invoice_id' => $invoiceId,
        ]);

        if ($this->wantsJsonPaymentResponse($request)) {
            $payload = $this->paymentService->paymentStatusPayload($uuid, $result, $gatewayId, $invoiceId);
            if (! $this->requesterOwnsPaymentUuid($request, $uuid) && ! $this->gatewayReturnVerified($request, $payload)) {
                $payload = $this->minimalPaymentStatus($payload);
            }

            return $this->apiResponse(
                $payload,
                $paid ? trans('api.success') : trans('api.error'),
                $paid ? 200 : 400
            );
        }

        return redirect()->away($frontendUrl);
    }

    /**
     * @param  array{id?: ?string, invoice_id?: ?string}  $extra
     */
    private function frontendPaymentRedirectUrl(string $type, string $uuid, array $extra = []): string
    {
        // دفعة (هـ) — E5: دفعة رسم (chg-{uuid}-{id}) ⇒ العودة لصفحة الطلب (الرابط الذكي) مع معرّف الرسم.
        $parsed = \App\Models\Payment::parseChargeKey($uuid);
        if ($parsed !== null) {
            return \App\Support\SmartLink::forOrder($parsed['uuid']).'?charge='.$parsed['charge_id'].'&status='.($type === 'error' ? 'failed' : 'success').'&paid='.($type === 'error' ? '0' : '1');
        }

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

        $query = [
            'status' => $type === 'error' ? 'failed' : 'success',
            'paid' => $type === 'error' ? '0' : '1',
        ];

        if (! empty($extra['id'])) {
            $query['id'] = (string) $extra['id'];
        }
        if (! empty($extra['invoice_id'])) {
            $query['invoice_id'] = (string) $extra['invoice_id'];
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query($query);
    }

    private function wantsJsonPaymentResponse(Request $request): bool
    {
        // Frontend fetch sends Accept: application/json; Moyasar browser visits do not.
        if ($request->query('format') === 'json') {
            return true;
        }

        $accept = strtolower((string) $request->header('Accept', ''));

        return str_contains($accept, 'application/json');
    }

    /** GET /payment/apple-pay/{uuid} — إعدادات Apple Pay داخل التطبيق + المبلغ من الخادم. */
    public function applePayConfig(string $uuid)
    {
        return $this->apiResponse($this->paymentService->applePayConfig((string) $uuid), trans('api.success'));
    }

    public function syncFromGateway(Request $request, string $uuid)
    {
        return $this->apiResponse(
            $this->paymentService->syncGatewayPaymentStatus(
                (string) $uuid,
                $request->input('id') ?? $request->input('payment_id'),
                $request->input('invoice_id')
            ),
            trans('api.success')
        );
    }

    public function sendSmsMessage($body, $recipients, $sender, $smsId)
    {
        return app(TaqnyatSmsService::class)->sendAndLog(
            $body,
            $recipients,
            'api_payment',
            auth()->id(),
            $sender,
            $smsId
        );
    }

    private function formatPhoneNumber($mobile)
    {
        $mobile = (string) $mobile;

        $formattedNumber = preg_replace('/^0|\+/', '', $mobile);

        if (! str_starts_with($formattedNumber, '00966')) {
            $formattedNumber = '00966'.$formattedNumber;
        }

        return $formattedNumber;
    }
}
