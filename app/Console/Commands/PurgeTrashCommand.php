<?php

namespace App\Console\Commands;

use App\Services\Orders\TrashService;
use Illuminate\Console\Command;

/** دفعة (د) — ب12: حذف نهائي لما مضى عليه 30 يوماً في السلة. */
class PurgeTrashCommand extends Command
{
    protected $signature = 'trash:purge';

    protected $description = 'Permanently delete orders / lessor-change requests kept in the trash for more than 30 days';

    public function handle(TrashService $trash): int
    {
        $result = $trash->purge();
        $this->info("purged contracts: {$result['contracts']}, lessor changes: {$result['lessor_changes']}");

        return self::SUCCESS;
    }
}
