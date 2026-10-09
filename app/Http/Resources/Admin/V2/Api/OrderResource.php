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
            // دفعة (د) — ب2/ب11: مفتاح الحالة الثابت وعلامات التأخير.
            'status_key' => \App\Models\ContractStatus::keyForId($this->contract_status_id ? (int) $this->contract_status_id : null),
            'delay_flags' => $delayFlags = $this->listDelayFlags(),
            'is_delayed' => $delayFlags !== [],
            // دفعة (هـ): شارة الدفع + «بانتظار دفع فرق» + «بانتظار العميل» + أعمدة التصدير.
            'payment_state' => $paymentSummary = app(\App\Services\Payments\ContractPaymentState::class)->summaryForList($this->resource),
            'paid_original' => $paymentSummary['paid_original'],
            'paid_extra' => $paymentSummary['paid_extra'],
            'refunded_total' => $paymentSummary['refunded_total'],
            'net_total' => $paymentSummary['net_total'],
            'payment_method' => $paymentSummary['method'],
            'payment_method_label' => $paymentSummary['method_label'],
            'awaiting_charge' => $paymentSummary['awaiting_charge'],
            'pending_charges_total' => $paymentSummary['pending_charges_total'],
            'data_request_pending' => $this->listDataRequestPending(),
        ];
    }

    /** علامات التأخير المحفوظة + customer_no_reply_24h الحيّة من طلبات المرفق المعلّقة. */
    private function listDelayFlags(): array
    {
        $flags = is_array($this->delay_flags) ? array_values($this->delay_flags) : [];
        $pending = $this->listDataRequestPending();
        if ($pending !== null && (int) $pending['hours'] >= (int) config('data_requests.reminder_after_hours', 24) && ! in_array('customer_no_reply_24h', $flags, true)) {
            $flags[] = 'customer_no_reply_24h';
        }

        return $flags;
    }

    /** @return array{request_id: int, section: string, items: list<string>, hours: int, label: string}|null */
    private function listDataRequestPending(): ?array
    {
        if (! $this->relationLoaded('dataRequests')) {
            return app(\App\Services\DataRequests\ContractDataRequestService::class)->pendingSummary($this->resource);
        }
        $pending = $this->dataRequests->where('status', \App\Models\ContractDataRequest::STATUS_PENDING)->sortBy('requested_at');
        if ($pending->isEmpty()) {
            return null;
        }
        $oldest = $pending->first();
        $items = $pending->flatMap(fn ($r) => $r->itemLabels())->unique()->values()->all();
        $since = $oldest->requested_at ?? $oldest->created_at;

        return [
            'request_id' => $oldest->id,
            'section' => $oldest->section,
            'items' => $items,
            'hours' => $since ? (int) \Illuminate\Support\Carbon::parse($since)->diffInHours(now()) : 0,
            'requested_at' => $since?->toIso8601String(),
            'reminded_at' => $oldest->reminded_at?->toIso8601String(),
            'label' => 'بانتظار العميل · '.implode('، ', $items),
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
