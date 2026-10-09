<?php

namespace App\Modules\Payments\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\Responser;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Payments\PaymentRefundService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * دفعة (د) — ب8: الاسترجاع عبر Moyasar من اللوحة.
 */
class PaymentRefundController extends Controller
{
    use Responser;

    /** POST /api/admin/payments/{payment}/refund { amount?, reason } */
    public function store(Request $request, int $payment, PaymentRefundService $refunds)
    {
        try {
            $data = $request->validate([
                'amount' => ['nullable', 'numeric', 'min:0.01'],
                'reason' => ['required', 'string', 'min:3', 'max:1000'],
            ]);

            $refund = $refunds->refund(
                Payment::query()->findOrFail($payment),
                isset($data['amount']) ? (float) $data['amount'] : null,
                (string) $data['reason'],
                $request->user() instanceof Employee ? $request->user() : null,
            );

            if ($refund->status === Refund::STATUS_FAILED) {
                return response()->json([
                    'message' => 'تعذّر الاسترجاع من بوابة الدفع: '.($refund->failure_message ?? ''),
                    'code' => 502,
                    'success' => false,
                    'data' => $refund->toAdminArray(),
                ], 502);
            }

            return $this->apiResponse($refund->load('employee:id,name')->toAdminArray(), 'تم الاسترجاع بنجاح.');
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
                'code' => 422,
                'success' => false,
            ], 422);
        }
    }

    /** GET /api/admin/payments/refunds — قائمة الاسترجاعات (صفحة «المرتجعات»). */
    public function index(Request $request)
    {
        $paginator = Refund::query()->with('employee:id,name')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('contract_uuid', 'like', '%'.trim((string) $request->input('search')).'%'))
            ->latest('id')
            ->paginate($this->perPageFromRequest($request, 25));

        $items = collect($paginator->items())->map(fn (Refund $r) => $r->toAdminArray())->values();

        return $this->paginatedApiResponse($paginator, $items, trans('api.success'), [
            'summary' => [
                'succeeded_total' => round((float) Refund::query()->where('status', Refund::STATUS_SUCCEEDED)->sum('amount'), 2),
                'succeeded_count' => Refund::query()->where('status', Refund::STATUS_SUCCEEDED)->count(),
                'failed_count' => Refund::query()->where('status', Refund::STATUS_FAILED)->count(),
            ],
        ]);
    }
}
