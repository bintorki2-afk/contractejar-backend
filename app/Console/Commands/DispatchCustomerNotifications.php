<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\NotificationDispatch;
use App\Models\Payment;
use App\Services\CustomerNotificationService;
use App\Support\ContractEndDate;
use App\Support\ContractFrontendStatus;
use App\Support\ContractJourney;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * الإشعارات الذكية المجدولة (ف8) — تُشغَّل كل 15 دقيقة (بتوقيت الرياض):
 *  - order_abandoned_24h / order_abandoned_3d: مسودة ظاهرة للعميل (الخطوات 4–6) لم تُرسل منذ 24 ساعة / 3 أيام.
 *  - awaiting_payment_2h: طلب مُرسل وغير مدفوع منذ ساعتين.
 *  - renewal_60d / renewal_30d: عقد موثّق ينتهي بعد 60 / 30 يوماً.
 * كل نوع يُرسل مرة واحدة فقط لكل طلب (جدول notification_dispatches).
 */
class DispatchCustomerNotifications extends Command
{
    protected $signature = 'notifications:dispatch
        {--dry-run : عرض المرشحين بدون إرسال}
        {--only= : نوع واحد فقط (order_abandoned|awaiting_payment|renewal)}';

    protected $description = 'إرسال الإشعارات الذكية للعملاء (طلبات غير مكتملة، بانتظار الدفع، قرب انتهاء العقد)';

    /** أقصى عدد لكل نوع في التشغيلة الواحدة (حماية من الانفجار بعد توقف طويل). */
    private const BATCH_LIMIT = 300;

    public function handle(CustomerNotificationService $notifications): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = (string) ($this->option('only') ?? '');
        $now = now();

        $counts = [
            CustomerNotificationService::KIND_ORDER_ABANDONED_3D => 0,
            CustomerNotificationService::KIND_ORDER_ABANDONED_24H => 0,
            CustomerNotificationService::KIND_AWAITING_PAYMENT_2H => 0,
            CustomerNotificationService::KIND_RENEWAL_60D => 0,
            CustomerNotificationService::KIND_RENEWAL_30D => 0,
        ];

        if ($only === '' || $only === 'order_abandoned') {
            $counts[CustomerNotificationService::KIND_ORDER_ABANDONED_3D] = $this->dispatchAbandoned(
                $notifications, CustomerNotificationService::KIND_ORDER_ABANDONED_3D, $now->copy()->subHours(72), $dryRun
            );
            $counts[CustomerNotificationService::KIND_ORDER_ABANDONED_24H] = $this->dispatchAbandoned(
                $notifications, CustomerNotificationService::KIND_ORDER_ABANDONED_24H, $now->copy()->subHours(24), $dryRun
            );
        }

        if ($only === '' || $only === 'awaiting_payment') {
            $counts[CustomerNotificationService::KIND_AWAITING_PAYMENT_2H] = $this->dispatchAwaitingPayment(
                $notifications, $now->copy()->subHours(2), $dryRun
            );
        }

        if ($only === '' || $only === 'renewal') {
            $renewals = $this->dispatchRenewals($notifications, $now, $dryRun);
            $counts[CustomerNotificationService::KIND_RENEWAL_60D] = $renewals[CustomerNotificationService::KIND_RENEWAL_60D];
            $counts[CustomerNotificationService::KIND_RENEWAL_30D] = $renewals[CustomerNotificationService::KIND_RENEWAL_30D];
        }

        try {
            Cache::put('scheduler.last_run', $now->toIso8601String(), now()->addDays(2));
            Cache::put('notifications.dispatch.last_run', [
                'at' => $now->toIso8601String(),
                'counts' => $counts,
                'dry_run' => $dryRun,
            ], now()->addDays(2));
        } catch (\Throwable) {
            // الكاش غير متاح — لا يؤثر على الإرسال.
        }

        foreach ($counts as $kind => $count) {
            $this->line(sprintf('%-22s %d', $kind, $count));
        }

        Log::info('notifications:dispatch finished', ['counts' => $counts, 'dry_run' => $dryRun]);

        return self::SUCCESS;
    }

    private function dispatchAbandoned(CustomerNotificationService $notifications, string $kind, Carbon $before, bool $dryRun): int
    {
        $query = $this->eligibleContracts()
            ->where('is_completed', 0)
            ->where('step', '>=', Contract::CUSTOMER_VISIBLE_MIN_STEP)
            ->where('step', '<', 7)
            ->where('updated_at', '<=', $before)
            ->whereNotIn('id', $this->dispatchedContractIds($kind));

        if ($kind === CustomerNotificationService::KIND_ORDER_ABANDONED_24H) {
            // من وصله تذكير الأيام الثلاثة لا يحتاج تذكير الـ24 ساعة بعده.
            $query->whereNotIn('id', $this->dispatchedContractIds(CustomerNotificationService::KIND_ORDER_ABANDONED_3D));
        }

        $sent = 0;
        foreach ($query->orderBy('updated_at')->limit(self::BATCH_LIMIT)->get() as $contract) {
            if ($dryRun) {
                $this->line("[dry-run] {$kind} → order {$contract->uuid}");
                $sent++;

                continue;
            }
            if ($notifications->orderAbandoned($contract, $kind) !== null) {
                $sent++;
            }
        }

        return $sent;
    }

    private function dispatchAwaitingPayment(CustomerNotificationService $notifications, Carbon $before, bool $dryRun): int
    {
        $kind = CustomerNotificationService::KIND_AWAITING_PAYMENT_2H;

        $query = $this->eligibleContracts()
            ->where('is_completed', 0)
            ->where('is_draft', false)
            ->where('step', '>=', 7)
            ->where('updated_at', '<=', $before)
            ->whereNotIn('id', $this->dispatchedContractIds($kind))
            ->whereNotIn('uuid', Payment::query()->successful()->select('contract_uuid'));

        $sent = 0;
        foreach ($query->orderBy('updated_at')->limit(self::BATCH_LIMIT)->get() as $contract) {
            if ($dryRun) {
                $this->line("[dry-run] {$kind} → order {$contract->uuid}");
                $sent++;

                continue;
            }
            if ($notifications->awaitingPayment($contract) !== null) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @return array<string, int>
     */
    private function dispatchRenewals(CustomerNotificationService $notifications, Carbon $now, bool $dryRun): array
    {
        $counts = [
            CustomerNotificationService::KIND_RENEWAL_60D => 0,
            CustomerNotificationService::KIND_RENEWAL_30D => 0,
        ];

        $notarizedStatusIds = ContractStatus::query()->get(['id', 'name'])
            ->filter(fn (ContractStatus $s) => in_array(ContractFrontendStatus::keyFromName($s->name), ContractJourney::NOTARIZED_KEYS, true))
            ->pluck('id')
            ->all();

        if ($notarizedStatusIds === []) {
            return $counts;
        }

        $today = $now->copy()->startOfDay();

        $this->eligibleContracts()
            ->where('is_completed', 1)
            ->whereIn('contract_status_id', $notarizedStatusIds)
            ->where('created_at', '>=', $now->copy()->subYears(4))
            ->orderBy('id')
            ->chunkById(200, function ($contracts) use (&$counts, $notifications, $today, $dryRun) {
                foreach ($contracts as $contract) {
                    $end = ContractEndDate::for($contract);
                    if ($end === null) {
                        continue;
                    }

                    $daysLeft = (int) $today->diffInDays($end->copy()->startOfDay(), false);

                    $kind = match (true) {
                        $daysLeft >= 54 && $daysLeft <= 60 => CustomerNotificationService::KIND_RENEWAL_60D,
                        $daysLeft >= 24 && $daysLeft <= 30 => CustomerNotificationService::KIND_RENEWAL_30D,
                        default => null,
                    };

                    if ($kind === null) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("[dry-run] {$kind} → order {$contract->uuid} (ends {$end->toDateString()}, {$daysLeft} days)");
                        $counts[$kind]++;

                        continue;
                    }

                    if ($notifications->renewal($contract, $kind, $daysLeft) !== null) {
                        $counts[$kind]++;
                    }
                }
            });

        return $counts;
    }

    /**
     * الطلبات التي يملكها مستخدم يمكن إشعاره: نشط، غير مدموج، وليس ضيفاً بلا توكن.
     */
    private function eligibleContracts(): Builder
    {
        return Contract::query()
            ->with('user')
            ->where('is_delete', 0)
            ->whereHas('user', function ($q) {
                $q->where('is_active', 1)
                    ->whereNull('merged_into_user_id')
                    ->where(function ($u) {
                        $u->where('is_guest', false)
                            ->orWhere(fn ($g) => $g->whereNotNull('fcm_token')->where('fcm_token', '!=', ''));
                    });
            });
    }

    private function dispatchedContractIds(string $kind): \Illuminate\Database\Query\Builder
    {
        return NotificationDispatch::query()
            ->where('kind', $kind)
            ->whereNotNull('contract_id')
            ->select('contract_id')
            ->toBase();
    }
}
