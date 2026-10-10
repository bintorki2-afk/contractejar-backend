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
            // دفعة (و) — B14: الطلب المُرسل/المستلم (حتى لو غير مدفوع) ليس مسودة قابلة للاستئناف.
            ->notSubmitted()
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
            // دفعة (و) — B15: رقم الطلب المعروض للعميل (لا المعرّف الداخلي).
            'order_number' => (string) $contract->uuid,
            'order_number_label' => 'رقم الطلب',
        ];
    }
}
