<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\ContractStatus;

/**
 * دفعة (و) — B14: «مسودة» = طلب لم يُرسل بعد. الطلب المُرسل (أكمل الخطوة 6 ⇒ step ≥ 7) أو المدفوع
 * أو الذي تجاوز «جديد» أو استلمه موظف ليس مسودة — حتى لو لم يُدفع (لا يظهر «أكمل مسودتك» ولا «استئناف»).
 */
final class ContractSubmission
{
    /** الخطوة بعد «إرسال الطلب» (step6 يضع step = 7). */
    public const SUBMITTED_STEP = 7;

    public static function isSubmitted(Contract $contract): bool
    {
        if ((int) $contract->step >= self::SUBMITTED_STEP || (bool) $contract->is_completed) {
            return true;
        }
        $statusId = (int) ($contract->contract_status_id ?? 0);
        if ($statusId > 0 && $statusId !== ContractStatus::newId()) {
            return true;
        }

        if ($contract->relationLoaded('receivedContract')) {
            return $contract->receivedContract !== null;
        }

        return SchemaCache::hasTable('received_contracts') && $contract->receivedContract()->exists();
    }

    public static function isResumableDraft(Contract $contract): bool
    {
        return (int) $contract->is_delete === 0 && ! self::isSubmitted($contract);
    }
}
