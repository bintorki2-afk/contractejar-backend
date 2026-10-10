<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\ContractStatusHistory;
use App\Services\ContractStatusHistoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * رحلة الطلب (دفعة هـ — E3): ثلاث خطوات فقط:
 *
 *  ① قيد المراجعة (تتم عند الدفع) → ② مستلم من الموظف → ③ تم التوثيق (مكتمل = نفس الخطوة الأخيرة)
 *
 * حالات جانبية تُعرض بدل الخطوات: ملغي / مسترجع.
 * لا توجد مرحلة «إرسال المسودة» بعد الآن (المفتاح whatsapp_draft بيانات تاريخية فقط).
 */
final class ContractJourney
{
    public const RULE_SENTENCE = 'بعد الدفع يستلم موظفنا طلبك ويوثّق العقد في إيجار مباشرةً، وتصلك إشعارات بكل خطوة.';

    /** @var list<array{key: string, label: string, description: string}> */
    public const STEPS = [
        ['key' => 'under_review', 'label' => 'قيد المراجعة', 'description' => 'تم استلام دفعتك وطلبك قيد المراجعة.'],
        ['key' => 'received_by_employee', 'label' => 'مستلم من الموظف', 'description' => 'استلم موظفنا طلبك وبدأ العمل عليه.'],
        ['key' => 'ejar_authenticated', 'label' => 'تم التوثيق', 'description' => 'تم توثيق العقد في منصة إيجار 🎉.'],
    ];

    /** الحالات الجانبية التي تُعرض بدل الخطوات. */
    public const SIDE_STATES = [
        'cancelled' => ['label' => 'ملغي', 'color' => '#EF4444'],
        'refunded' => ['label' => 'مسترجع', 'color' => '#DC2626'],
    ];

    /** مفاتيح سجل الحالات التي تعني أن الخطوة ① بدأت. */
    private const REVIEW_KEYS = ['paid', 'under_review', 'received', 'received_by_employee', 'whatsapp_draft'];

    /** مفاتيح سجل الحالات التي تعني استلام الموظف. */
    private const RECEIVED_KEYS = ['received', 'received_by_employee', 'whatsapp_draft'];

    /** مفاتيح سجل الحالات التي تعني اكتمال التوثيق. */
    public const NOTARIZED_KEYS = ['ejar_authenticated', 'completed'];

    /**
     * @return list<array{step: int, key: string, label: string, description: string, done: bool, current: bool, at: string|null, by: string|null}>
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
        $received = $notarized
            || $contract->receivedContract()->exists()
            || collect(self::RECEIVED_KEYS)->contains(static fn (string $k) => $byKey->has($k))
            || self::currentKey($contract, self::RECEIVED_KEYS);
        $reviewed = $received
            || (bool) $contract->is_completed
            || collect(self::REVIEW_KEYS)->contains(static fn (string $k) => $byKey->has($k))
            || self::currentKey($contract, ['under_review']);

        $done = [
            'under_review' => $reviewed,
            'received_by_employee' => $received,
            'ejar_authenticated' => $notarized,
        ];

        $receivedRow = $contract->relationLoaded('receivedContract') ? $contract->receivedContract : $contract->receivedContract()->with('employee')->first();
        $receivedRow?->loadMissing('employee');
        $receivedAt = $receivedRow?->created_at ? Carbon::parse($receivedRow->created_at)->toIso8601String() : null;

        $at = [
            'under_review' => $firstAt(['paid', 'under_review']) ?? $firstAt(self::REVIEW_KEYS),
            'received_by_employee' => $receivedAt ?? $firstAt(self::RECEIVED_KEYS),
            'ejar_authenticated' => $firstAt(self::NOTARIZED_KEYS),
        ];

        $by = [
            'under_review' => null,
            'received_by_employee' => $receivedRow?->employee?->name,
            'ejar_authenticated' => self::notarizedBy($contract),
        ];

        // QA-F ORDERS-RES-13: قبل الدفع الخطوة ① ليست «قيد المراجعة» فعلاً — تُعلَّم «بانتظار الدفع».
        $awaitingPayment = ! $reviewed;

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
                'by' => $isDone ? ($by[$step['key']] ?? null) : null,
                'awaiting_payment' => $isCurrent && $index === 0 && $awaitingPayment,
                'current_label' => $isCurrent && $index === 0 && $awaitingPayment ? 'بانتظار الدفع' : ($isCurrent ? $step['label'] : null),
            ];
        }

        return $steps;
    }

    /**
     * الحالة الجانبية الحالية (ملغي/مسترجع) أو null.
     *
     * @return array{key: string, label: string, color: string, at: string|null}|null
     */
    public static function sideState(?Contract $contract): ?array
    {
        if ($contract === null) {
            return null;
        }

        $key = ContractStatus::keyForId($contract->contract_status_id ? (int) $contract->contract_status_id : null);
        if ($key === null) {
            $row = $contract->relationLoaded('contractStatus') ? $contract->contractStatus : $contract->contractStatus()->first();
            $key = ContractFrontendStatus::keyForStatusRow($row);
        }
        if ($key === null || ! array_key_exists($key, self::SIDE_STATES)) {
            if ((int) $contract->is_delete === 1 && filled($contract->trashed_at ?? null) && (bool) $contract->is_completed) {
                $key = 'cancelled';
            } else {
                return null;
            }
        }

        $at = ContractStatusHistory::query()->where('contract_id', $contract->id)->where('status', $key)->orderByDesc('id')->value('created_at');

        return [
            'key' => $key,
            'label' => self::SIDE_STATES[$key]['label'],
            'color' => self::SIDE_STATES[$key]['color'],
            'at' => $at ? Carbon::parse($at)->toIso8601String() : null,
        ];
    }

    /**
     * القالب الخام (بدون حالة) — للواجهات قبل وجود طلب.
     *
     * @return list<array{step: int, key: string, label: string, description: string, done: bool, current: bool, at: null, by: null}>
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
                'by' => null,
            ];
        }

        return $steps;
    }

    /**
     * هل هذه الحالة الهدف تعني «توثيق العقد» (تخضع لقاعدة الدفع أولاً)؟
     */
    public static function isNotarizationStatus(?int $statusId, ?string $statusName): bool
    {
        if ($statusId !== null && $statusId === ContractStatus::idFor(ContractStatus::KEY_EJAR_AUTHENTICATED)) {
            return true;
        }

        return in_array(ContractFrontendStatus::keyFromName($statusName), self::NOTARIZED_KEYS, true);
    }

    private static function notarizedBy(Contract $contract): ?string
    {
        if (! \App\Support\SchemaCache::hasTable('contract_activities')) {
            return null;
        }

        $row = \App\Models\ContractActivity::query()
            ->where('contract_id', $contract->id)
            ->where('action', 'stage_notarized')
            ->orderByDesc('id')
            ->first(['actor_name']);

        return $row?->actor_name;
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

        return in_array(ContractFrontendStatus::keyForStatusRow($row) ?? ContractFrontendStatus::keyFromName($row->name), $keys, true);
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
