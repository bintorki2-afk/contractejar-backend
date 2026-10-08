<?php

namespace App\Modules\LessorChange\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V2\InvoiceResource;
use App\Models\LessorChangeRequest;
use App\Models\Setting;
use App\Modules\Auth\Support\AuthMobile;
use App\Modules\LessorChange\Requests\StoreLessorChangeRequest;
use App\Services\ContractInvoiceService;
use App\Services\MoyasarPaymentService;
use App\Shared\Responses\Responser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * خدمة «تغيير المؤجر» — للعميل (زائر بجلسة ضيف أو حساب موثّق).
 */
class LessorChangeController extends Controller
{
    use Responser;

    public function __construct(private readonly MoyasarPaymentService $payments) {}

    /** السعر الحالي + نص التنبيه (عام). */
    public function info()
    {
        return $this->apiResponse([
            'fee' => self::currentFee(),
            'currency' => 'SAR',
            'notice' => 'جميع العقود المرتبطة بالصك القديم ستنتقل إلى الصك الجديد.',
            'required' => [
                ['key' => 'old_deed_image', 'label' => 'صك المالك القديم'],
                ['key' => 'new_deed_image', 'label' => 'صك المالك الجديد'],
                ['key' => 'new_owner_id_number', 'label' => 'رقم هوية المالك الجديد'],
                ['key' => 'new_owner_dob', 'label' => 'تاريخ ميلاد المالك الجديد'],
            ],
        ], trans('api.success'));
    }

    /** إنشاء الطلب (multipart). يُعيد رقم الطلب ورابط الدفع. */
    public function store(StoreLessorChangeRequest $request)
    {
        $user = $request->user();
        $mobile = $request->input('mobile') ?: ($user->contact_mobile ?? $user->mobile ?? null);

        $old = $request->file('old_deed_image')->store(LessorChangeRequest::DIR, LessorChangeRequest::DISK);
        $new = $request->file('new_deed_image')->store(LessorChangeRequest::DIR, LessorChangeRequest::DISK);

        $dob = sprintf('%02d/%02d/%04d', (int) $request->new_owner_dob_day, (int) $request->new_owner_dob_month, (int) $request->new_owner_dob_year);

        $row = LessorChangeRequest::create([
            'uuid' => LessorChangeRequest::generateUuid(),
            'user_id' => $user->id,
            'mobile' => $mobile ? AuthMobile::normalizeSaudiMobile((string) $mobile) : null,
            'old_deed_image' => $old,
            'new_deed_image' => $new,
            'new_owner_id_number' => $request->new_owner_id_number,
            'new_owner_dob' => $dob,
            'new_owner_dob_type' => $request->new_owner_dob_type,
            'notes' => $request->input('notes'),
            'fee' => self::currentFee(),
            'status' => 'pending_payment',
            'platform' => $request->input('platform', 'web'),
        ]);

        if ($mobile && $user->is_guest && empty($user->contact_mobile)) {
            try {
                $user->forceFill(['contact_mobile' => AuthMobile::normalizeSaudiMobile((string) $mobile)])->save();
            } catch (\Throwable) {
            }
        }

        return $this->apiResponse($row->toClientArray(), 'تم إنشاء الطلب', 201);
    }

    /** طلباتي. */
    public function mine(Request $request)
    {
        $rows = LessorChangeRequest::query()
            ->where('user_id', $request->user()->id)
            ->where('is_delete', false)
            ->latest('id')
            ->get()
            ->map->toClientArray()
            ->values();

        return $this->apiResponse($rows, trans('api.success'));
    }

    public function show(Request $request, string $uuid)
    {
        $row = LessorChangeRequest::query()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->where('is_delete', false)
            ->first();

        if (! $row) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        return $this->apiResponse($row->toClientArray(), trans('api.success'));
    }

    /**
     * فاتورة طلب تغيير المؤجر (بعد الدفع) — نفس شكل فاتورة العقد.
     * GET /api/v2/lessor-change/{uuid}/invoice
     */
    public function invoice(Request $request, string $uuid, ContractInvoiceService $invoices)
    {
        $row = LessorChangeRequest::query()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->where('is_delete', false)
            ->first();

        if (! $row) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        // قبل الدفع: معاينة فقط (لا يُنشأ سجل فاتورة).
        return $this->apiResponse(
            new InvoiceResource($invoices->forLessorChange($row, persist: $row->isPaid())),
            trans('api.success')
        );
    }

    /**
     * رابط الدفع (عام مع تقييد المعدل — نفس نمط دفع العقود).
     * GET /api/v2/payment/lessor-change/{uuid}
     */
    public function pay(Request $request, string $uuid)
    {
        $row = LessorChangeRequest::query()->where('uuid', $uuid)->where('is_delete', false)->first();
        if (! $row) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        if ($row->status !== 'pending_payment') {
            return $this->apiResponse(array_merge($row->toClientArray(), ['already_paid' => true]), trans('api.success'));
        }

        $client = (string) ($request->query('platform') ?? $request->query('client') ?? $request->header('X-Client') ?? $row->platform ?? 'web');

        try {
            $payment = $this->payments->requestServicePaymentUrl(
                $row->uuid,
                (float) $row->fee,
                'Lessor change '.$row->uuid,
                $client === 'app' ? 'app' : 'web'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->errorMessage(trans('api.not_accept'), 400);
        }

        return $this->apiResponse([
            'already_paid' => false,
            'kind' => 'lessor_change',
            'order_number' => $row->uuid,
            'uuid' => $row->uuid,
            'payment_url' => $payment['payment_url'],
            'Payment_url' => $payment['payment_url'],
            'invoice_id' => $payment['invoice_id'] ?? null,
            'cart_amount' => (float) $row->fee,
            'total' => (float) $row->fee,
            'fee' => (float) $row->fee,
            'payment_success_url' => $payment['payment_success_url'] ?? null,
            'payment_error_url' => $payment['payment_error_url'] ?? null,
        ], trans('api.success'));
    }

    /** صورة الصك (رابط موقّع مؤقت). */
    public function image(LessorChangeRequest $request, string $field): StreamedResponse
    {
        abort_unless(in_array($field, ['old_deed_image', 'new_deed_image'], true), 404);
        $path = (string) $request->{$field};
        abort_if($path === '' || ! Storage::disk(LessorChangeRequest::DISK)->exists($path), 404);

        return Storage::disk(LessorChangeRequest::DISK)->response($path);
    }

    public static function currentFee(): float
    {
        try {
            $value = Setting::query()->value('lessor_change_fee');
        } catch (\Throwable) {
            $value = null;
        }

        return $value !== null && is_numeric($value) && (float) $value > 0 ? (float) $value : 400.0;
    }
}
