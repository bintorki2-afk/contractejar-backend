<?php

namespace App\Services\Orders;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\LessorChangeRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * سلة المحذوفات (دفعة د — ب12): الحذف من اللوحة ينقل للسلة (is_delete + trashed_at + deleted_by)
 * وتمكن الاستعادة خلال 30 يوماً، ثم يُحذف نهائياً بالأمر trash:purge. الدفعات لا تُحذف أبداً.
 */
class TrashService
{
    public const RETENTION_DAYS = 30;

    public function __construct(private readonly OrderFlowService $flow) {}

    public function trashContract(Contract $contract, ?Employee $by): Contract
    {
        $before = ['is_delete' => (int) $contract->is_delete];
        $contract->forceFill(['is_delete' => 1, 'trashed_at' => now(), 'deleted_by' => $by?->id])->save();
        $this->flow->activity($contract, 'deleted', $by, $before, ['is_delete' => 1]);

        return $contract;
    }

    public function restoreContract(Contract $contract, ?Employee $by): Contract
    {
        $this->assertRestorable($contract->trashed_at);
        $contract->forceFill(['is_delete' => 0, 'trashed_at' => null, 'deleted_by' => null])->save();
        $this->flow->activity($contract, 'restored', $by, ['is_delete' => 1], ['is_delete' => 0]);

        return $contract;
    }

    public function trashLessorChange(LessorChangeRequest $row, ?Employee $by): LessorChangeRequest
    {
        $row->forceFill(['is_delete' => true, 'trashed_at' => now(), 'deleted_by' => $by?->id])->save();
        app(ContractActivityLogger::class)->log(null, 'deleted', $by, null, ['lessor_change_request_id' => $row->id], $by ? 'employee' : 'system', null, $row);

        return $row;
    }

    public function restoreLessorChange(LessorChangeRequest $row, ?Employee $by): LessorChangeRequest
    {
        $this->assertRestorable($row->trashed_at);
        $row->forceFill(['is_delete' => false, 'trashed_at' => null, 'deleted_by' => null])->save();
        app(ContractActivityLogger::class)->log(null, 'restored', $by, null, ['lessor_change_request_id' => $row->id], $by ? 'employee' : 'system', null, $row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function trashMeta(mixed $trashedAt, ?int $deletedBy): array
    {
        $at = $trashedAt ? Carbon::parse($trashedAt) : null;
        $purgeAt = $at?->copy()->addDays(self::RETENTION_DAYS);

        return [
            'trashed_at' => $at?->toIso8601String(),
            'purge_at' => $purgeAt?->toIso8601String(),
            'days_left' => $purgeAt ? max(0, (int) ceil(now()->diffInSeconds($purgeAt, false) / 86400)) : null,
            'deleted_by' => $deletedBy,
            'deleted_by_name' => $deletedBy ? Employee::query()->whereKey($deletedBy)->value('name') : null,
            'restorable' => $at !== null && $at->gt(now()->subDays(self::RETENTION_DAYS)),
        ];
    }

    /** يحذف نهائياً ما مضى عليه 30 يوماً في السلة. @return array{contracts: int, lessor_changes: int} */
    public function purge(): array
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $contracts = 0;
        Contract::query()->where('is_delete', 1)->whereNotNull('trashed_at')->where('trashed_at', '<', $cutoff)
            ->orderBy('id')->chunkById(100, function ($rows) use (&$contracts) {
                foreach ($rows as $contract) {
                    try {
                        $contract->delete();
                        $contracts++;
                    } catch (\Throwable $e) {
                        Log::warning('trash purge failed for contract', ['contract_id' => $contract->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        $lessor = LessorChangeRequest::query()->where('is_delete', true)->whereNotNull('trashed_at')->where('trashed_at', '<', $cutoff)->delete();

        return ['contracts' => $contracts, 'lessor_changes' => (int) $lessor];
    }

    private function assertRestorable(mixed $trashedAt): void
    {
        if ($trashedAt === null) {
            throw new \InvalidArgumentException('العنصر ليس في السلة.');
        }
        if (Carbon::parse($trashedAt)->lte(now()->subDays(self::RETENTION_DAYS))) {
            throw new \InvalidArgumentException('انتهت مدة الاستعادة (30 يوماً).');
        }
    }
}
