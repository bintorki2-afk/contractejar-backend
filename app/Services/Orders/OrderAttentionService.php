<?php

namespace App\Services\Orders;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\ContractStatusHistory;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * «عليك الحين» وتنبيهات التأخير (دفعة د — ب11).
 *
 * قواعد التأخير:
 *  - paid_not_received   : مدفوع ولم يُستلم بعد أكثر من ساعتين من الدفع.
 *  - received_no_draft   : مستلم ولم تُرسل المسودة بعد 24 ساعة من الاستلام.
 *  - draft_no_notarize   : أُرسلت المسودة ولم يُوثّق بعد 72 ساعة.
 */
class OrderAttentionService
{
    public const RULES = [
        'paid_not_received' => ['hours' => 2, 'label' => 'مدفوع ولم يُستلم (+ساعتين)'],
        'received_no_draft' => ['hours' => 24, 'label' => 'مستلم بلا مسودة (+24 ساعة)'],
        'draft_no_notarize' => ['hours' => 72, 'label' => 'مسودة بلا توثيق (+72 ساعة)'],
    ];

    private const CLOSED = [
        ContractStatus::KEY_EJAR_AUTHENTICATED, ContractStatus::KEY_COMPLETED, ContractStatus::KEY_CANCELLED,
        ContractStatus::KEY_REFUNDED, ContractStatus::KEY_ON_HOLD,
    ];

    /**
     * @return array{counts: array<string, int>, awaiting_receive: list<array<string, mixed>>, awaiting_draft: list<array<string, mixed>>, awaiting_notarize: list<array<string, mixed>>, delayed: list<array<string, mixed>>, rules: array<string, mixed>, generated_at: string}
     */
    public function board(int $limit = 50): array
    {
        $now = now();
        $buckets = ['awaiting_receive' => [], 'awaiting_draft' => [], 'awaiting_notarize' => [], 'delayed' => []];

        foreach ($this->openPaidOrders() as $contract) {
            $row = $this->row($contract, $now);
            if ($row['bucket'] !== null) {
                $buckets[$row['bucket']][] = $row;
            }
            if ($row['delay_flags'] !== []) {
                $buckets['delayed'][] = $row;
            }
        }

        $counts = [];
        foreach ($buckets as $key => $rows) {
            usort($rows, static fn ($a, $b) => strcmp((string) $a['since'], (string) $b['since'])); // الأقدم أولاً
            $counts[$key] = count($rows);
            $buckets[$key] = array_slice($rows, 0, $limit);
        }

        return array_merge($buckets, [
            'counts' => $counts,
            'rules' => collect(self::RULES)->map(fn ($r, $k) => ['key' => $k, 'hours' => $r['hours'], 'label' => $r['label']])->values()->all(),
            'generated_at' => $now->toIso8601String(),
        ]);
    }

    /**
     * يضبط delay_flags على كل الطلبات المفتوحة ويرجع الطلبات التي ظهر لها تأخير جديد.
     *
     * @return list<array{contract: Contract, new_flags: list<string>}>
     */
    public function flagAll(): array
    {
        $now = now();
        $newlyFlagged = [];
        $openIds = [];

        foreach ($this->openPaidOrders() as $contract) {
            $openIds[] = $contract->id;
            $flags = $this->flagsFor($contract, $now);
            $old = is_array($contract->delay_flags) ? $contract->delay_flags : [];
            $new = array_values(array_diff($flags, $old));
            if ($flags !== $old) {
                $contract->forceFill([
                    'delay_flags' => $flags !== [] ? $flags : null,
                    'delay_flagged_at' => $new !== [] ? $now : $contract->delay_flagged_at,
                ])->saveQuietly();
            }
            if ($new !== []) {
                $newlyFlagged[] = ['contract' => $contract, 'new_flags' => $new];
            }
        }

        // الطلبات التي أُغلقت/تقدّمت: تُمسح علاماتها.
        Contract::query()->whereNotNull('delay_flags')->whereNotIn('id', $openIds ?: [-1])
            ->update(['delay_flags' => null]);

        return $newlyFlagged;
    }

    /**
     * @return list<string>
     */
    public function flagsFor(Contract $contract, ?Carbon $now = null): array
    {
        $now ??= now();
        $row = $this->row($contract, $now);

        return $row['delay_flags'];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Contract $contract, Carbon $now): array
    {
        $key = ContractStatus::keyForId($contract->contract_status_id ? (int) $contract->contract_status_id : null);
        $received = $contract->receivedContract;
        $receivedAt = $received ? Carbon::parse($received->created_at ?? $received->date_of_received) : null;
        $paidAt = $this->paidAt($contract);

        $bucket = null;
        $since = null;
        $flags = [];

        if ($key === ContractStatus::KEY_WHATSAPP_DRAFT) {
            $bucket = 'awaiting_notarize';
            $since = $this->statusSince($contract, 'whatsapp_draft') ?? Carbon::parse($contract->updated_at);
            if ($since->lte($now->copy()->subHours(self::RULES['draft_no_notarize']['hours']))) {
                $flags[] = 'draft_no_notarize';
            }
        } elseif ($received !== null) {
            $bucket = 'awaiting_draft';
            $since = $receivedAt;
            if ($since && $since->lte($now->copy()->subHours(self::RULES['received_no_draft']['hours']))) {
                $flags[] = 'received_no_draft';
            }
        } elseif (in_array($key, [null, ContractStatus::KEY_NEW, ContractStatus::KEY_PAID, ContractStatus::KEY_UNDER_REVIEW], true)) {
            $bucket = 'awaiting_receive';
            $since = $paidAt ?? Carbon::parse($contract->created_at);
            if ($since->lte($now->copy()->subHours(self::RULES['paid_not_received']['hours']))) {
                $flags[] = 'paid_not_received';
            }
        }

        $since ??= Carbon::parse($contract->created_at);
        $status = \App\Support\ContractFrontendStatus::for($contract);

        return [
            'id' => $contract->id,
            'uuid' => (string) $contract->uuid,
            'bucket' => $bucket,
            'customer_name' => $contract->user?->name,
            'customer_mobile' => $contract->user?->contact_mobile ?: $contract->user?->mobile,
            'contract_type' => $contract->contract_type,
            'status' => $status['status'],
            'status_label' => $status['status_label'],
            'employee_id' => $received?->employee_id,
            'employee_name' => $received?->employee?->name,
            'paid_at' => $paidAt?->toIso8601String(),
            'since' => $since->toIso8601String(),
            'age_minutes' => (int) $since->diffInMinutes($now),
            'delay_flags' => $flags,
            'delay_labels' => array_map(static fn ($f) => self::RULES[$f]['label'], $flags),
            'is_delayed' => $flags !== [],
        ];
    }

    /**
     * @return Collection<int, Contract>
     */
    private function openPaidOrders(): Collection
    {
        $closedIds = ContractStatus::idsFor(self::CLOSED);

        return Contract::query()
            ->adminListed()
            ->where('is_completed', 1)
            ->where('is_draft', false)
            ->when($closedIds !== [], fn ($q) => $q->where(fn ($w) => $w->whereNull('contract_status_id')->orWhereNotIn('contract_status_id', $closedIds)))
            ->when(\App\Support\SchemaCache::hasColumn('contracts', 'is_synthetic'), fn ($q) => $q->where(fn ($w) => $w->whereNull('is_synthetic')->orWhere('is_synthetic', false)))
            ->with(['user', 'receivedContract.employee', 'contractStatus'])
            ->orderBy('id')
            ->limit(2000)
            ->get();
    }

    private function paidAt(Contract $contract): ?Carbon
    {
        $history = ContractStatusHistory::query()->where('contract_id', $contract->id)->where('status', 'paid')->orderBy('id')->value('created_at');
        if ($history) {
            return Carbon::parse($history);
        }
        $payment = filled($contract->uuid)
            ? Payment::query()->successfulMatchingContractUuid((string) $contract->uuid)->orderBy('id')->value('created_at')
            : null;

        return $payment ? Carbon::parse($payment) : null;
    }

    private function statusSince(Contract $contract, string $status): ?Carbon
    {
        $at = ContractStatusHistory::query()->where('contract_id', $contract->id)->where('status', $status)->orderByDesc('id')->value('created_at');

        return $at ? Carbon::parse($at) : null;
    }
}
