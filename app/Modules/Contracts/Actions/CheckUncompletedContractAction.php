<?php

namespace App\Modules\Contracts\Actions;

use App\Models\Contract;

class CheckUncompletedContractAction
{
    /**
     * @return array{check: bool, contract_id?: int, uuid?: string, step?: mixed, contract_type?: mixed}
     */
    public function execute(int $userId, string $contractType): array
    {
        $contract = Contract::query()
            ->incompleteForUser($userId, $contractType)
            ->visibleToCustomer()
            ->latest('created_at')
            ->first();

        if (! $contract) {
            return ['check' => false];
        }

        return [
            'check' => true,
            'contract_id' => $contract->id,
            'uuid' => (string) $contract->uuid,
            'step' => $contract->step,
            'contract_type' => $contract->contract_type,
        ];
    }
}
