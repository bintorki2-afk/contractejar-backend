<?php

namespace App\Modules\Contracts\Actions;

use App\Models\Contract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListUserContractsAction
{
    public function paginateForApi(int $userId): LengthAwarePaginator
    {
        return Contract::query()
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->where('step', '>', '6')
            ->where('is_delete', 0)
            ->paginate(10);
    }

    /**
     * @param  array{status_id?: int, drafts?: bool, draft_status_id?: int}  $filters
     */
    public function paginateForApiV2(int $userId, array $filters = []): LengthAwarePaginator
    {
        $query = Contract::query()
            ->where('user_id', $userId)
            ->with(['realEstate', 'contractStatus', 'draftContractStatus', 'receivedContract', 'statusHistories'])
            ->orderBy('updated_at', 'desc')
            ->where('is_delete', 0)
            ->reachedAdminOrderStep()
            ->visibleToCustomer();

        if (! empty($filters['status_id'])) {
            $query->where('contract_status_id', (int) $filters['status_id']);
        }

        if (! empty($filters['drafts'])) {
            $query->draft();
        }

        if (! empty($filters['draft_status_id'])) {
            $query->where('draft_contract_status_id', (int) $filters['draft_status_id']);
        }

        return $query->paginate(10);
    }
}
