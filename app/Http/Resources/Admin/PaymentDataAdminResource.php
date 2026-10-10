<?php

namespace App\Http\Resources\Admin;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentDataAdminResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // دفعة (هـ): دفعات الرسوم مفتاحها chg-{uuid}-{id} ⇒ الطلب عبر contract_id.
        $contract = $this->contract ?? ($this->contract_id ? \App\Models\Contract::query()->with('user')->find((int) $this->contract_id) : null);
        $user = optional($contract)->user;

        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'payment_date' => $this->payment_date ? Carbon::parse($this->payment_date)->format('Y-m-d') : null,
            'payment_hour' => $this->payment_date ? Carbon::parse($this->payment_date)->format('H:i') : null,
            'contract_uuid' => $contract?->uuid !== null ? (string) $contract->uuid : $this->contract_uuid,
            'payment_key' => $this->contract_uuid,
            'contract_type' => $contract?->contract_type,
            'payment_method' => $this->payment_method,
            'tran_currency' => $this->tran_currency,
            'name_payment' => $this->name,
            'name' => $this->name,
            'status' => $this->status,
            // دفعة (هـ) — 2.7
            'kind' => $this->kind ?: ($this->payment_method === 'bank_transfer' ? 'bank_transfer' : 'original'),
            'kind_label' => match ($this->kind ?: ($this->payment_method === 'bank_transfer' ? 'bank_transfer' : 'original')) {
                'price_difference' => 'فرق سعر', 'extra_fee' => 'رسوم إضافية', 'bank_transfer' => 'حوالة بنكية', default => 'الدفعة الأصلية',
            },
            'charge_id' => $this->charge_id,
            'contract_id' => $this->contract_id ?? $contract?->id,
            'reference' => $this->reference,
            'employee_id' => $this->employee_id,
            'receipt_url' => $this->receipt_path ? \App\Services\Payments\ContractPaymentState::receiptUrl($this->resource) : null,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'mobile' => $user->mobile,
                'email' => $user->email,
            ] : null,
            'user_mobile' => $user?->mobile,
        ];
    }
}
