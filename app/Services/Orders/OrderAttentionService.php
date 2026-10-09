<?php

namespace App\Services\Orders;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\ContractStatusHistory;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * «عليك الحين» وتنبيهات التأخير (دفعة د — ب11، دفعة هـ — E3/E4).
 *
 * قواعد التأخير:
 *  - paid_not_received      : مدفوع ولم يُستلم بعد أكثر من ساعتين من الدفع.
 *  - received_not_notarized : مستلم ولم يُوثّق بعد 24 ساعة من الاستلام (حلّت محل قاعدتي المسودة).
 *  - customer_no_reply_24h  : طلب مرفق ناقص لم يرد عليه العميل منذ 24 ساعة (72 ساعة ⇒ تنبيه المالك).
 */
class OrderAttentionService
{
    public const RULES = [
        'paid_not_received' => ['hours' => 2, 'label' => 'مدفوع ولم يُستلم (+ساعتين)'],
        'received_not_notarized' => ['hours' => 24, 'label' => 'مستلم بلا توثيق (+24 ساعة)'],
        'customer_no_reply_24h' => ['hours' => 24, 'label' => 'عميل لم يرد على طلب مرفق (+24 ساعة)'],
        'customer_no_reply_72h' => ['hours' => 72, 'label' => 'عميل لم يرد منذ 72 ساعة (نُبّه المالك)'],
    ];

    private const CLOSED = [
        ContractStatus::KEY_EJAR_AUTHENTICATED, ContractStatus::KEY_COMPLETED, ContractStatus::KEY_CANCELLED,
        ContractStatus::KEY_REFUNDED, ContractStatus::KEY_ON_HOLD,
    ];

    /**
     * @return array{counts: array<string, int>, awaiting_receive: list<array<string, mixed>>, awaiting_notarize: list<array<string, mixed>>, awaiting_customer: array{count: int, items: list<array<string, mixed>>}, delayed: list<array<string, mixed>>, rules: array<string, mixed>, generated_at: string}
     */
    public function board(int $limit = 50): array
    {
        $now = now();
        $buckets = ['awaiting_receive' => [], 'awaiting_notarize' => [], 'delayed' => []];

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

        // دفعة (هـ) — E4: عملاء لم يردوا على طلب مرفق ناقص منذ 24 ساعة.
        $awaitingCustomer = app(\App\Services\DataRequests\ContractDataRequestService::class)->awaitingCustomer($limit);
        $counts['awaiting_customer'] = $awaitingCustomer['count'];
        $counts['delayed'] += $awaitingCustomer['count'];
        $buckets['delayed'] = array_slice(array_merge($buckets['delayed'], $awaitingCustomer['items']), 0, $limit);

        // دفعة (هـ) — E2: طلبات غير مدفوعة استلمها موظف (للتذكير بتحصيلها).
        $unpaidReceived = $this->unpaidReceived($limit);
        $counts['unpaid_received'] = $unpaidReceived['count'];

        return array_merge($buckets, [
            'awaiting_customer' => $awaitingCustomer,
            'unpaid_received' => $unpaidReceived,
            'counts' => $counts,
            'rules' => collect(self::RULES)->map(fn ($r, $k) => ['key' => $k, 'hours' => $r['hours'], 'label' => $r['label']])->values()->all(),
            'generated_at' => $now->toIso8601String(),
        ]);
    }

    /**
     * طلبات مستلمة ولم تُدفع بعد (الموظف يعمل عليها لكن التوثيق مقفل حتى الدفع).
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    public function unpaidReceived(int $limit = 50): array
    {
        $rows = Contract::query()->adminListed()->where('is_completed', 0)->where('is_draft', false)
            ->whereHas('receivedContract')
            ->when(\App\Support\SchemaCache::hasColumn('contracts', 'is_synthetic'), fn ($q) => $q->where(fn ($w) => $w->whereNull('is_synthetic')->orWhere('is_synthetic', false)))
            ->with(['user', 'receivedContract.employee', 'contractStatus'])
            ->orderBy('id')->limit(500)->get();

        $items = $rows->take($limit)->map(function (Contract $c) {
            $status = \App\Support\ContractFrontendStatus::for($c);
            $since = $c->receivedContract?->created_at ? Carbon::parse($c->receivedContract->created_at) : Carbon::parse($c->created_at);

            return [
                'id' => $c->id, 'uuid' => (string) $c->uuid, 'bucket' => 'unpaid_received',
                'customer_name' => $c->user?->name, 'customer_mobile' => $c->user?->contact_mobile ?: $c->user?->mobile,
                'contract_type' => $c->contract_type, 'status' => $status['status'], 'status_label' => $status['status_label'],
                'employee_id' => $c->receivedContract?->employee_id, 'employee_name' => $c->receivedContract?->employee?->name,
                'since' => $since->toIso8601String(), 'age_minutes' => (int) $since->diffInMinutes(now()),
                'delay_flags' => [], 'delay_labels' => [], 'is_delayed' => false,
            ];
        })->values()->all();

        return ['count' => $rows->count(), 'items' => $items];
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

        if ($received !== null || $key === ContractStatus::KEY_WHATSAPP_DRAFT) {
            // دفعة (هـ): بعد الاستلام ⇒ التوثيق مباشرةً (بلا مسودة).
            $bucket = 'awaiting_notarize';
            $since = $receivedAt ?? $this->statusSince($contract, 'received_by_employee') ?? Carbon::parse($contract->updated_at);
            if ($since->lte($now->copy()->subHours(self::RULES['received_not_notarized']['hours']))) {
                $flags[] = 'received_not_notarized';
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

        // دفعة (هـ) — E4: طلب مرفق ناقص بلا رد.
        $pendingSummary = app(\App\Services\DataRequests\ContractDataRequestService::class)->pendingSummary($contract);
        if ($pendingSummary !== null && (int) $pendingSummary['hours'] >= (int) config('data_requests.reminder_after_hours', 24)) {
            $flags[] = 'customer_no_reply_24h';
            if ((int) $pendingSummary['hours'] >= (int) config('data_requests.owner_alert_after_hours', 72)) {
                $flags[] = 'customer_no_reply_72h';
            }
        }

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
            'data_request_pending' => $pendingSummary,
            'payment_state' => app(\App\Services\Payments\ContractPaymentState::class)->summaryForList($contract),
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
