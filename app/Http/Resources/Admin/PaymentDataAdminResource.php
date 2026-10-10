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
            // دفعة (و) — D4: PDF الفاتورة (صفحة «الفواتير» في اللوحة) — للدفعات الناجحة فقط.
            ...$this->invoicePdfFields($contract),
        ];
    }

    /** @return array{invoice_pdf_url: string|null, invoice_pdf_download_url: string|null, invoice_source: string|null} */
    private function invoicePdfFields(?\App\Models\Contract $contract): array
    {
        $none = ['invoice_pdf_url' => null, 'invoice_pdf_download_url' => null, 'invoice_source' => null];
        if ($this->status !== 'success') {
            return $none;
        }
        $url = null;
        $source = null;
        if ($contract !== null) {
            $url = \App\Services\Invoices\InvoicePdfService::contractUrl($contract);
            $source = 'contract';
        } elseif (filled($this->contract_uuid)) {
            $lessor = \App\Models\LessorChangeRequest::query()->where('uuid', (string) $this->contract_uuid)->first();
            if ($lessor !== null) {
                $url = \App\Services\Invoices\InvoicePdfService::lessorChangeUrl($lessor);
                $source = $url !== null ? 'lessor_change' : null;
            }
        }

        return [
            'invoice_pdf_url' => $url,
            'invoice_pdf_download_url' => \App\Services\Invoices\InvoicePdfService::downloadUrl($url),
            'invoice_source' => $source,
        ];
    }
}
