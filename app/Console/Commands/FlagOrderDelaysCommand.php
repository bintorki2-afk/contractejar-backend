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

    protected $description = 'Flag delayed orders (paid-not-received >2h, received-no-draft >24h, draft-no-notarize >72h) and notify employees';

    public function handle(OrderAttentionService $attention, OrderFlowService $flow, FirebaseNotificationService $firebase): int
    {
        if ($this->option('dry-run')) {
            $board = $attention->board();
            $this->info('delayed: '.$board['counts']['delayed']);
            foreach ($board['delayed'] as $row) {
                $this->line("#{$row['uuid']} ".implode(', ', $row['delay_flags']));
            }

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

        Cache::put('orders.flag_delays.last_run', now()->toIso8601String(), now()->addDays(2));
        $this->info('newly delayed: '.count($flagged));

        return self::SUCCESS;
    }
}
