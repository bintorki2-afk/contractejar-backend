<?php

namespace App\Console\Commands;

use App\Services\FirebaseNotificationService;
use App\Services\Orders\OrderAttentionService;
use App\Services\Orders\OrderFlowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * دفعة (د) — ب11: يضبط علامات تأخير الطلبات ويُشعر الموظفين بالتأخير الجديد فقط.
 */
class FlagOrderDelaysCommand extends Command
{
    protected $signature = 'orders:flag-delays {--dry-run : عرض دون حفظ أو إشعار}';

    protected $description = 'Flag delayed orders (paid-not-received >2h, received-not-notarized >24h, customer-no-reply 24h/72h) and notify employees + owner';

    public function handle(OrderAttentionService $attention, OrderFlowService $flow, FirebaseNotificationService $firebase): int
    {
        $dataRequests = app(\App\Services\DataRequests\ContractDataRequestService::class);

        if ($this->option('dry-run')) {
            $board = $attention->board();
            $this->info('delayed: '.$board['counts']['delayed']);
            foreach ($board['delayed'] as $row) {
                $this->line("#{$row['uuid']} ".implode(', ', $row['delay_flags']));
            }
            $this->info('owner alerts (72h, dry): '.$dataRequests->alertOwnerForStale(true));

            return self::SUCCESS;
        }

        $flagged = $attention->flagAll();
        foreach ($flagged as $item) {
            $contract = $item['contract'];
            $labels = array_map(static fn ($f) => OrderAttentionService::RULES[$f]['label'], $item['new_flags']);
            $flow->activity($contract, 'delay_flagged', null, null, ['delay_flags' => $item['new_flags']], 'system', implode('، ', $labels));
            try {
                $firebase->sendToAllEmployees(
                    '⏰ طلب متأخر',
                    'الطلب رقم '.$contract->uuid.': '.implode('، ', $labels),
                    ['type' => 'order_delay', 'contract_id' => (string) $contract->id, 'contract_uuid' => (string) $contract->uuid, 'delay_flags' => implode(',', $item['new_flags'])]
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // دفعة (هـ) — E4: تنبيه المالك عبر تيليجرام بعد 72 ساعة بلا رد (مرة واحدة لكل طلب).
        $alerts = $dataRequests->alertOwnerForStale(false);

        Cache::put('orders.flag_delays.last_run', now()->toIso8601String(), now()->addDays(2));
        $this->info('newly delayed: '.count($flagged).' · owner alerts: '.$alerts);

        return self::SUCCESS;
    }
}
