<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\ContractStatusHistory;
use App\Services\ContractStatusHistoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * رحلة الطلب (ف2) — القالب الثابت من 6 خطوات للعميل، مع علامات الإنجاز من سجل الحالات:
 *
 *  ① استلام الطلب → ② الدفع → ③ مراجعة الفريق للبيانات → ④ إرسال مسودة العقد عبر واتساب
 *  → ⑤ اطلاعك على المسودة → ⑥ توثيق العقد في إيجار 🎉
 *
 * القاعدة المنتجية: لا نوثّق العقد في إيجار إلا بعد اطلاع العميل على المسودة المرسلة عبر واتساب.
 */
final class ContractJourney
{
    public const RULE_MESSAGE = 'لا يمكن توثيق العقد قبل إرسال المسودة للعميل عبر واتساب';

    public const RULE_SENTENCE = 'بعد الدفع نرسل لك مسودة العقد عبر واتساب للاطلاع عليها، ولا نوثّق العقد في إيجار إلا بعد اطلاعك على المسودة.';

    /** @var list<array{key: string, label: string, description: string}> */
    public const STEPS = [
        ['key' => 'received', 'label' => 'استلام الطلب', 'description' => 'استلمنا طلبك وسجّلناه برقم الطلب.'],
        ['key' => 'paid', 'label' => 'الدفع', 'description' => 'تم استلام دفعتك وبدأ فريقنا بالمراجعة.'],
        ['key' => 'under_review', 'label' => 'مراجعة الفريق للبيانات', 'description' => 'يراجع فريقنا بيانات الطلب والمستندات.'],
        ['key' => 'whatsapp_draft', 'label' => 'إرسال مسودة العقد عبر واتساب', 'description' => 'تصلك مسودة العقد عبر واتساب للاطلاع عليها.'],
        ['key' => 'draft_reviewed', 'label' => 'اطلاعك على المسودة', 'description' => 'تطّلع على المسودة وتؤكد لنا صحتها.'],
        ['key' => 'ejar_authenticated', 'label' => 'توثيق العقد في إيجار', 'description' => 'نوثّق العقد في منصة إيجار 🎉.'],
    ];

    /** مفاتيح سجل الحالات التي تعني أن الخطوة ③ (المراجعة) بدأت. */
    private const REVIEW_KEYS = ['under_review', 'received', 'received_by_employee'];

    /** مفاتيح سجل الحالات التي تعني اكتمال التوثيق. */
    public const NOTARIZED_KEYS = ['ejar_authenticated', 'completed'];

    /**
     * @return list<array{step: int, key: string, label: string, description: string, done: bool, current: bool, at: string|null}>
     */
    public static function for(?Contract $contract): array
    {
        if ($contract === null) {
            return self::template();
        }

        $history = self::history($contract);
        $byKey = $history->groupBy('status');

        $firstAt = static function (array $keys) use ($byKey): ?string {
            $rows = collect($keys)->flatMap(static fn (string $key) => $byKey->get($key, collect()));
            $row = $rows->sortBy('id')->first();

            return $row?->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null;
        };

        // الخطوات تراكمية: إنجاز خطوة لاحقة يعني إنجاز كل ما قبلها.
        $notarized = collect(self::NOTARIZED_KEYS)->contains(static fn (string $k) => $byKey->has($k))
            || self::currentKey($contract, self::NOTARIZED_KEYS);
        $draftSent = $notarized
            || $byKey->has('whatsapp_draft')
            || filled($contract->ejar_contract_draft_number)
            || self::currentKey($contract, ['whatsapp_draft']);
        $reviewed = $draftSent
            || collect(self::REVIEW_KEYS)->contains(static fn (string $k) => $byKey->has($k))
            || self::currentKey($contract, self::REVIEW_KEYS);
        $paid = $reviewed || (bool) $contract->is_completed || $byKey->has('paid');
        $submitted = $paid || (int) $contract->step >= 7;

        $done = [
            'received' => $submitted,
            'paid' => $paid,
            'under_review' => $reviewed,
            'whatsapp_draft' => $draftSent,
            'draft_reviewed' => $notarized,
            'ejar_authenticated' => $notarized,
        ];

        $at = [
            'received' => $firstAt(['new']) ?? ($contract->created_at ? Carbon::parse($contract->created_at)->toIso8601String() : null),
            'paid' => $firstAt(['paid']),
            'under_review' => $firstAt(self::REVIEW_KEYS),
            'whatsapp_draft' => $firstAt(['whatsapp_draft']),
            'draft_reviewed' => null,
            'ejar_authenticated' => $firstAt(self::NOTARIZED_KEYS),
        ];

        $currentAssigned = false;
        $steps = [];
        foreach (self::STEPS as $index => $step) {
            $isDone = (bool) $done[$step['key']];
            $isCurrent = false;
            if (! $isDone && ! $currentAssigned) {
                $isCurrent = true;
                $currentAssigned = true;
            }
            $steps[] = [
                'step' => $index + 1,
                'key' => $step['key'],
                'label' => $step['label'],
                'description' => $step['description'],
                'done' => $isDone,
                'current' => $isCurrent,
                'at' => $isDone ? ($at[$step['key']] ?? null) : null,
            ];
        }

        return $steps;
    }

    /**
     * القالب الخام (بدون حالة) — للواجهات قبل وجود طلب.
     *
     * @return list<array{step: int, key: string, label: string, description: string, done: bool, current: bool, at: null}>
     */
    public static function template(): array
    {
        $steps = [];
        foreach (self::STEPS as $index => $step) {
            $steps[] = [
                'step' => $index + 1,
                'key' => $step['key'],
                'label' => $step['label'],
                'description' => $step['description'],
                'done' => false,
                'current' => $index === 0,
                'at' => null,
            ];
        }

        return $steps;
    }

    /**
     * هل أُرسلت المسودة للعميل عبر واتساب؟ (شرط التوثيق)
     */
    public static function draftWasSent(Contract $contract): bool
    {
        if (filled($contract->ejar_contract_draft_number)) {
            return true;
        }

        if ((int) $contract->contract_status_id === ContractStatus::WHATSAPP_DRAFT_ID
            || self::currentKey($contract, ['whatsapp_draft'])) {
            return true;
        }

        return ContractStatusHistory::query()
            ->where('contract_id', $contract->id)
            ->where('status', 'whatsapp_draft')
            ->exists();
    }

    /**
     * هل هذه الحالة الهدف تعني «توثيق العقد» (تخضع لقاعدة المسودة أولاً)؟
     */
    public static function isNotarizationStatus(?int $statusId, ?string $statusName): bool
    {
        if ($statusId === ContractStatus::EJAR_AUTHENTICATION_ID) {
            return true;
        }

        return in_array(ContractFrontendStatus::keyFromName($statusName), self::NOTARIZED_KEYS, true);
    }

    /**
     * @param  list<string>  $keys
     */
    private static function currentKey(Contract $contract, array $keys): bool
    {
        if ((bool) $contract->is_draft) {
            return false;
        }

        if (! $contract->contract_status_id) {
            return false;
        }

        $row = $contract->relationLoaded('contractStatus')
            ? $contract->contractStatus
            : $contract->contractStatus()->first();
        if ($row === null) {
            return false;
        }

        return in_array(ContractFrontendStatus::keyFromName($row->name), $keys, true);
    }

    /**
     * @return Collection<int, ContractStatusHistory>
     */
    private static function history(Contract $contract): Collection
    {
        if (! $contract->exists) {
            return collect();
        }

        app(ContractStatusHistoryService::class)->ensureSeeded($contract);

        if ($contract->relationLoaded('statusHistories') && $contract->statusHistories->isNotEmpty()) {
            return $contract->statusHistories->sortBy('id')->values();
        }

        return ContractStatusHistory::query()
            ->where('contract_id', $contract->id)
            ->orderBy('id')
            ->get();
    }
}
