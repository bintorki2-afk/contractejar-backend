<?php

namespace App\Http\Resources\Admin\V2\Api;

use App\Http\Resources\Admin\V2\Api\Concerns\ResolvesContractPaymentForAdmin;
use App\Http\Resources\Admin\V2\Api\Concerns\ResolvesContractReturnAcceptance;
use App\Http\Resources\Admin\V2\Api\Concerns\ResolvesContractReturnOrderFields;
use App\Models\Contract;
use App\Models\ReceivedContract;
use App\Support\ContractReceivedTiming;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    use ResolvesContractPaymentForAdmin;
    use ResolvesContractReturnAcceptance;
    use ResolvesContractReturnOrderFields;

    public function toArray(Request $request): array
    {
        $receivedContract = $this->resolveReceivedContractRow();
        $payment = $this->contractPaymentFields();

        // True iff `received_contracts.contract_id` = this contract id (row exists).
        $receivedContractExists = $receivedContract !== null;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'contract_type' => $this->contract_type_trans,
            'contract_type_key' => $this->contract_type,
            'amount_payment' => $payment['amount_payment'],
            'is_paid' => $payment['is_paid'],
            'payment_status' => $payment['payment_status'],
            'payment_label_ar' => $payment['payment_label_ar'],
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'contract_status_id' => $this->contract_status_id,
            'status' => [
                'id' => $this->is_draft
                    ? ($this->draft_contract_status_id ?? $this->contract_status_id)
                    : $this->contract_status_id,
                'name' => $this->is_draft
                    ? ($this->draftContractStatus?->name ?? $this->contractStatus?->name)
                    : $this->contractStatus?->name,
                'color' => $this->is_draft
                    ? ($this->draftContractStatus?->color ?? $this->contractStatus?->color)
                    : $this->contractStatus?->color,
            ],
            'draft_contract_status_id' => $this->draft_contract_status_id,
            'draft_contract_status' => $this->when(
                (bool) $this->is_draft || $this->draft_contract_status_id,
                fn () => $this->draftContractStatus ? [
                    'id' => $this->draftContractStatus->id,
                    'name' => $this->draftContractStatus->name,
                    'color' => $this->draftContractStatus->color,
                    'color_text' => $this->draftContractStatus->color_text,
                ] : null
            ),
            'is_received' => $receivedContractExists,
            'received_contract_exists' => $receivedContractExists,
            'employee_name' => $receivedContract?->employee?->name ?? 'لم يتم الاستلام',
            ...ContractReceivedTiming::for($this->resource, $receivedContract),
            'user_id' => $this->user_id,
            'user_name' => $this->user->name ?? null,
            // زائر الموقع: جوال الواتساب في contact_mobile. (DASHBOARD-8)
            'user_mobile' => ($this->user?->mobile ?: null) ?? ($this->user?->contact_mobile ?? null),
            'ownership' => $this->contract_ownership,
            ...$this->instrumentTypeFields(),
            'is_completed' => (bool) $this->is_completed,
            'is_draft' => (bool) $this->is_draft,
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            ...$this->returnAcceptanceFields(),
            ...$this->returnOrderFields(),
        ];
    }

    /**
     * Row from `received_contracts` for this contract id (via contract_id), with employee when loaded from DB.
     */
    private function resolveReceivedContractRow(): ?ReceivedContract
    {
        if ($this->relationLoaded('receivedContract')) {
            return $this->receivedContract;
        }

        return ReceivedContract::query()
            ->where('contract_id', $this->resource->getKey())
            ->with('employee')
            ->first();
    }

    /**
     * @return array{
     *     instrument_type: ?string,
     *     instrument_type_key: ?string,
     *     instrument_type_trans: ?string,
     *     instrument_type_label: ?string
     * }
     */
    private function instrumentTypeFields(): array
    {
        $key = is_string($this->instrument_type) ? trim($this->instrument_type) : '';
        if ($key === '') {
            return [
                'instrument_type' => null,
                'instrument_type_key' => null,
                'instrument_type_trans' => null,
                'instrument_type_label' => null,
            ];
        }

        $canonical = Contract::normalizeInstrumentType($key) ?? $key;
        $label = Contract::instrumentTypeLabel($canonical, 'ar');

        return [
            'instrument_type' => $label,
            'instrument_type_key' => $canonical,
            'instrument_type_trans' => $label,
            'instrument_type_label' => $label,
        ];
    }
}
