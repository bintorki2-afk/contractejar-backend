<?php

namespace App\Http\Resources\Admin\V2\Api;

use App\Http\Resources\Api\V2\Contract\Concerns\MapsContractStatusFields;
use App\Models\Payment;
use App\Services\Admin\RefundableContractService;
use App\Support\RefundableContractReference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RefundableContractListResource extends JsonResource
{
    use MapsContractStatusFields;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $contract = $this->contract;
        $uuid = $contract?->uuid;
        $approval = $this->managementApprovalFields();

        return [
            'id' => $this->id,
            'refund_id' => $this->id,
            'refundable_contract_id' => $this->id,
            'order_number' => $contract ? str_pad((string) $contract->id, 6, '0', STR_PAD_LEFT) : null,
            'draft_contract_number' => $contract ? str_pad((string) $contract->id, 6, '0', STR_PAD_LEFT) : null,
            'contract_id' => $this->contract_id,
            'contract_uuid' => $uuid,
            'customer_mobile' => $contract?->user?->mobile,
            'customer_name' => $contract?->user
                ? trim(($contract->user->fname ?? '').' '.($contract->user->lname ?? ''))
                : null,
            'contract_type' => $contract?->contract_type_trans,
            'contract_type_key' => $contract?->contract_type,
            'instrument_type' => $contract?->instrument_type_trans,
            'instrument_type_key' => $contract?->instrument_type,
            ...self::contractStatusFieldsFor($contract, 'قيد التنفيذ'),
            'contract_status' => $contract?->contractStatus ? [
                'id' => $contract->contractStatus->id,
                'name' => $contract->contractStatus->name,
                'color' => $contract->contractStatus->color,
            ] : null,
            'is_return_order' => (int) $contract?->contract_status_id === RefundableContractService::returnStatusId(),
            'payment_amount' => $this->resolvePaymentAmount($uuid),
            'refund_amount' => (float) $this->refund_amount,
            'admin_confirmed' => $this->admin_confirmed,
            'is_refunded' => (bool) $this->is_refunded,
            'customer_refunded' => (bool) $this->is_refunded,
            'refunded' => (bool) $this->is_refunded,
            'refunded_status' => [
                'refunded' => (bool) $this->is_refunded,
                'label_ar' => $this->is_refunded ? 'تم الاسترجاع' : 'لم يتم الاسترجاع',
            ],
            'requester' => [
                'id' => $this->employee_id,
                'name' => $this->employee?->name,
            ],
            'management_approval' => $approval,
            'has_draft_contract' => (bool) $this->has_draft_contract,
            'notes' => $this->notes,
            'reference_number' => RefundableContractReference::for($this->resource),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{approved: bool|null, label_ar: string}
     */
    private function managementApprovalFields(): array
    {
        if ($this->admin_confirmed === null) {
            return [
                'approved' => null,
                'label_ar' => 'بانتظار الموافقة',
            ];
        }

        if ($this->admin_confirmed === true || $this->admin_confirmed === 1 || $this->admin_confirmed === '1') {
            return [
                'approved' => true,
                'label_ar' => 'تم الموافقة',
            ];
        }

        return [
            'approved' => false,
            'label_ar' => 'لم تتم الموافقة',
        ];
    }

    private function resolvePaymentAmount(?string $contractUuid): ?float
    {
        if (! $contractUuid) {
            return null;
        }

        $amount = Payment::query()
            ->where('contract_uuid', $contractUuid)
            ->where('status', 'success')
            ->orderByDesc('created_at')
            ->value('amount');

        return $amount !== null ? (float) $amount : null;
    }
}
