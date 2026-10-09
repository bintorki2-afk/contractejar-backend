<?php

namespace App\Http\Concerns;

use App\Models\Contract;
use App\Models\LessorChangeRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * نقاط الدفع العامة (عودة البوابة/نتيجة الدفع) يصل لها أي شخص برقم الطلب (6 أرقام).
 * نُعيد التفاصيل الداخلية (معرّف العقد، مبلغ ووسيلة الدفع، بيانات طلب تغيير المؤجر
 * كهوية المالك الجديد) فقط لصاحب الطلب (توكن) أو لمن عاد من البوابة بمعرّف دفع/فاتورة
 * تحقّقنا أنه لهذا الطلب. غيرهم يحصلون على ما يحتاجه الدافع فقط. (DASHBOARD-7 / APP-4)
 */
trait RedactsPublicPaymentPayload
{
    protected function requesterOwnsPaymentUuid(Request $request, string $uuid): bool
    {
        try {
            $user = $request->user() ?? Auth::guard('sanctum')->user();
        } catch (\Throwable) {
            $user = null;
        }

        if (! $user instanceof User) {
            return false;
        }

        // دفعة (هـ) — A-2: مفتاح رسم chg-{uuid}-{id} ⇒ مالك الطلب الأصل.
        $charge = \App\Models\Payment::parseChargeKey($uuid);
        $contractOwner = $charge !== null
            ? Contract::query()->where('uuid', $charge['uuid'])->value('user_id')
            : Contract::query()->where('uuid', $uuid)->value('user_id');
        if ($contractOwner !== null) {
            return (int) $contractOwner === (int) $user->id;
        }

        try {
            $lessor = LessorChangeRequest::findByUuid($uuid);
        } catch (\Throwable) {
            $lessor = null;
        }

        return $lessor !== null && (int) $lessor->user_id === (int) $user->id;
    }

    /**
     * عاد من البوابة بمعرّف دفع/فاتورة صريح وتحقّقت المزامنة أنه لهذا الطلب.
     *
     * @param  array<string, mixed>  $payload  paymentStatusPayload
     */
    protected function gatewayReturnVerified(Request $request, array $payload): bool
    {
        $hasExplicitId = filled($request->query('id') ?? $request->input('id') ?? $request->input('payment_id'))
            || filled($request->query('invoice_id') ?? $request->input('invoice_id'));

        return $hasExplicitId && (bool) ($payload['sync']['synced'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function minimalPaymentStatus(array $payload): array
    {
        return [
            'result' => $payload['result'] ?? null,
            'resolved_result' => $payload['resolved_result'] ?? null,
            'contract_uuid' => $payload['contract_uuid'] ?? null,
            'kind' => $payload['kind'] ?? 'contract',
            'is_completed' => (bool) ($payload['is_completed'] ?? false),
            'payment_confirmed' => (bool) ($payload['payment_confirmed'] ?? false),
        ];
    }
}
