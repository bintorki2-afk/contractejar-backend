<?php

namespace App\Services\Orders;

use App\Models\Contract;
use App\Models\ContractActivity;
use App\Models\ContractStatus;
use App\Models\Employee;
use App\Models\LessorChangeRequest;
use App\Support\SchemaCache;
use Illuminate\Support\Carbon;

/**
 * سجل نشاط الطلب (دفعة د — ب9): كل إجراء من اللوحة (أو النظام) يُسجَّل مع المنفّذ وقبل/بعد.
 * يُعرض كاملاً في تفاصيل الطلب باللوحة (`activities[]`) ونسخة آمنة للعميل (`activities[]` في رحلته).
 */
class ContractActivityLogger
{
    /** @var array<string, string> */
    public const LABELS = [
        'created' => 'إنشاء الطلب',
        'payment' => 'تم الدفع',
        'status_changed' => 'تغيير الحالة',
        'received' => 'استلام الطلب',
        'assigned' => 'إسناد الطلب',
        'stage_received' => 'مرحلة: الاستلام',
        'stage_draft_sent' => 'مرحلة: إرسال المسودة',
        'stage_notarized' => 'مرحلة: التوثيق',
        'edited' => 'تعديل بيانات الطلب',
        'cancelled' => 'إلغاء الطلب',
        'refunded' => 'استرجاع المبلغ',
        'refund_failed' => 'فشل الاسترجاع',
        'discount_applied' => 'تطبيق خصم',
        'note_added' => 'إضافة ملاحظة',
        'deleted' => 'نقل إلى السلة',
        'restored' => 'استعادة من السلة',
        'notification_sent' => 'إرسال إشعار',
        'delay_flagged' => 'تنبيه تأخير',
        // دفعة (هـ)
        'bank_transfer_recorded' => 'تسجيل حوالة بنكية',
        'charge_created' => 'إضافة رسوم / فرق سعر',
        'charge_link_sent' => 'رابط دفع الرسوم',
        'charge_paid' => 'دفع الرسوم',
        'charge_cancelled' => 'إلغاء الرسوم',
        'refund_due' => 'فرق لصالح العميل',
        'data_request_sent' => 'طلب مرفق ناقص',
        'data_request_reminded' => 'تذكير بطلب المرفق',
        'data_request_resolved' => 'حل طلب المرفق',
        'data_request_cancelled' => 'إلغاء طلب المرفق',
        'data_request_progress' => 'العميل أرسل جزءاً من المطلوب',
        'customer_edited' => 'تعديل من العميل بعد الإرسال',
        'ejar_entry_progress' => 'إدخال في إيجار',
        // دفعة (و) — D9
        'draft_document_uploaded' => 'رفع مسودة العقد للعميل',
        'draft_document_removed' => 'حذف مسودة العقد',
        'pay_after_draft_requested' => 'العميل اختار الدفع بعد مشاهدة المسودة',
    ];

    /** إجراءات تظهر للعميل (بدون أسماء الموظفين/الملاحظات الداخلية). */
    public const CUSTOMER_VISIBLE = [
        'payment', 'status_changed', 'received', 'assigned', 'stage_received', 'stage_draft_sent', 'stage_notarized',
        'cancelled', 'refunded', 'discount_applied',
        // دفعة (هـ)
        'bank_transfer_recorded', 'charge_created', 'charge_paid', 'data_request_sent', 'data_request_resolved',
        // دفعة (و)
        'data_request_progress', 'customer_edited',
        'draft_document_uploaded', 'pay_after_draft_requested',
    ];

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        ?Contract $contract,
        string $action,
        ?Employee $actor = null,
        ?array $before = null,
        ?array $after = null,
        string $actorType = 'employee',
        ?string $note = null,
        ?LessorChangeRequest $lessorChange = null,
    ): ?ContractActivity {
        if (! SchemaCache::hasTable('contract_activities')) {
            return null;
        }

        $actor ??= $actorType === 'employee' ? $this->currentEmployee() : null;
        if ($actor === null && $actorType === 'employee') {
            $actorType = 'system';
        }

        return ContractActivity::query()->create([
            'contract_id' => $contract?->id,
            'lessor_change_request_id' => $lessorChange?->id,
            'actor_type' => $actorType,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'action' => $action,
            'before' => $this->decorate($before),
            'after' => $this->decorate($after),
            'note' => $note,
            'customer_visible' => in_array($action, self::CUSTOMER_VISIBLE, true),
            'customer_label' => $this->customerLabel($action, $after),
        ]);
    }

    /**
     * فرق الحقول المتغيّرة فعلاً (قبل/بعد) — نصوص طويلة تُختصر.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $ignore
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public static function diff(Contract $contract, array $payload, array $ignore = []): array
    {
        $before = [];
        $after = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, $ignore, true) || ! is_string($key)) {
                continue;
            }
            $old = $contract->getOriginal($key);
            $normOld = is_array($old) ? json_encode($old, JSON_UNESCAPED_UNICODE) : (string) ($old ?? '');
            $normNew = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) ($value ?? '');
            if ($value instanceof \Illuminate\Http\UploadedFile) {
                $normNew = '[ملف جديد]';
            }
            if ($normOld === $normNew) {
                continue;
            }
            $before[$key] = mb_strimwidth($normOld, 0, 300, '…');
            $after[$key] = mb_strimwidth($normNew, 0, 300, '…');
        }

        return ['before' => $before, 'after' => $after];
    }

    /**
     * سجل كامل للّوحة.
     *
     * @return list<array<string, mixed>>
     */
    public function forAdmin(Contract $contract): array
    {
        if (! SchemaCache::hasTable('contract_activities')) {
            return [];
        }

        return ContractActivity::query()
            ->where('contract_id', $contract->id)
            ->orderBy('id')
            ->get()
            ->map(fn (ContractActivity $a) => [
                'id' => $a->id,
                'action' => $a->action,
                'action_label' => self::LABELS[$a->action] ?? $a->action,
                'actor_type' => $a->actor_type,
                'actor_id' => $a->actor_id,
                'actor_name' => $a->actor_name ?? ($a->actor_type === 'system' ? 'النظام' : null),
                'before' => $a->before,
                'after' => $a->after,
                'note' => $a->note,
                'customer_visible' => (bool) $a->customer_visible,
                'at' => $a->created_at?->toIso8601String(),
                'at_label' => $a->created_at ? Carbon::parse($a->created_at)->format('Y-m-d H:i') : null,
            ])
            ->values()
            ->all();
    }

    /**
     * نسخة آمنة للعميل (لا أسماء موظفين ولا ملاحظات داخلية ولا قيم قبل/بعد خام).
     *
     * @return list<array{action: string, label: string, at: string|null}>
     */
    public function forCustomer(Contract $contract): array
    {
        if (! SchemaCache::hasTable('contract_activities')) {
            return [];
        }

        return ContractActivity::query()
            ->where('contract_id', $contract->id)
            ->where('customer_visible', true)
            ->orderBy('id')
            ->get()
            ->map(fn (ContractActivity $a) => [
                'action' => $a->action,
                'label' => (string) ($a->customer_label ?: (self::LABELS[$a->action] ?? $a->action)),
                'at' => $a->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * يضيف أسماء الحالات لأرقامها لتقرأ اللوحة «من ← إلى» مباشرة.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function decorate(?array $values): ?array
    {
        if ($values === null || $values === []) {
            return null;
        }

        if (array_key_exists('contract_status_id', $values) && ! array_key_exists('status_name', $values)) {
            $id = $values['contract_status_id'] !== null ? (int) $values['contract_status_id'] : null;
            $values['status_key'] = ContractStatus::keyForId($id);
            $values['status_name'] = $id ? ContractStatus::query()->whereKey($id)->value('name') : null;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>|null  $after
     */
    private function customerLabel(string $action, ?array $after): ?string
    {
        return match ($action) {
            'status_changed' => isset($after['contract_status_id'])
                ? (string) (ContractStatus::query()->whereKey((int) $after['contract_status_id'])->value('name') ?? 'تحديث حالة الطلب')
                : 'تحديث حالة الطلب',
            'received', 'assigned', 'stage_received' => 'استلم فريقنا طلبك',
            'stage_draft_sent' => 'أرسلنا لك مسودة العقد عبر واتساب',
            'stage_notarized' => 'تم توثيق العقد في إيجار',
            'refunded' => isset($after['amount']) ? 'تم استرجاع '.rtrim(rtrim(number_format((float) $after['amount'], 2, '.', ''), '0'), '.').' ر.س' : 'تم استرجاع المبلغ',
            'discount_applied' => 'تم تطبيق خصم على طلبك',
            'cancelled' => 'تم إلغاء الطلب',
            'payment' => 'تم استلام دفعتك',
            // دفعة (هـ)
            'bank_transfer_recorded' => 'تم استلام دفعتك (حوالة بنكية)',
            'charge_created' => (($after['kind'] ?? '') === 'price_difference' ? 'فرق سعر' : 'رسوم إضافية').(isset($after['amount']) ? ' '.rtrim(rtrim(number_format((float) $after['amount'], 2, '.', ''), '0'), '.').' ر.س' : '').(filled($after['message'] ?? null) ? ' — '.$after['message'] : ''),
            'charge_paid' => 'تم استلام دفعتك'.(isset($after['amount']) ? ' ('.rtrim(rtrim(number_format((float) $after['amount'], 2, '.', ''), '0'), '.').' ر.س)' : ''),
            'data_request_sent' => 'طلبنا منك: '.implode('، ', (array) ($after['items'] ?? [])),
            'data_request_resolved' => 'أرسلت المطلوب — شكراً لك',
            'data_request_progress' => 'استلمنا جزءاً من المطلوب — بقي: '.implode('، ', (array) ($after['remaining'] ?? [])),
            'customer_edited' => 'عدّلت بيانات طلبك — سيراجعها الموظف',
            'draft_document_uploaded' => 'مسودة عقدك جاهزة — راجعها وادفع للتوثيق',
            'pay_after_draft_requested' => 'أرسلت طلبك — ستصلك مسودة العقد لمراجعتها قبل الدفع',
            default => null,
        };
    }

    private function currentEmployee(): ?Employee
    {
        try {
            $user = auth('sanctum')->user() ?? auth()->user();
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof Employee ? $user : null;
    }
}
