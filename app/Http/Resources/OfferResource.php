<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfferResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        $contract = $this->contract_id ? $this->contract : null;
        $lessorChange = $this->lessor_change_request_id ? $this->lessorChangeRequest : null;

        $orderNumber = $contract
            ? (string) $contract->uuid
            : ($lessorChange ? (string) $lessorChange->uuid : null);

        $smartLink = $contract
            ? \App\Support\SmartLink::for($contract)
            : ($lessorChange ? $lessorChange->smartLink() : null);

        $readAt = $this->read_at;
        if ($readAt === null && (bool) $this->is_read) {
            // إشعارات قديمة قُرئت قبل إضافة عمود read_at.
            $readAt = $this->updated_at;
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            // نوع الإشعار (ف8): draft_sent / notarized / payment_success / status_changed /
            // lessor_change_status / order_abandoned_24h / order_abandoned_3d / awaiting_payment_2h /
            // renewal_60d / renewal_30d / offer / announcement.
            'kind' => $this->kind ?? ($this->contract_id ? 'status_changed' : 'announcement'),
            // الرابط الذكي (https://contractejar.com/r/{رقم الطلب}) أو رابط العرض.
            'url' => $this->url ?? $smartLink,
            'is_read' => (bool) $this->is_read,
            'read_at' => $readAt ? \Illuminate\Support\Carbon::parse($readAt)->toIso8601String() : null,
            // ربط الإشعار بالطلب: يفتح التطبيق/الموقع الطلب مباشرة بدل البحث بالنص.
            'contract_id' => $this->contract_id,
            'contract_uuid' => $contract ? (string) $contract->uuid : null,
            'lessor_change_request_id' => $this->lessor_change_request_id,
            'order_number' => $orderNumber,
            'smart_link' => $smartLink,
            'data' => is_array($this->data) ? $this->data : null,
            'created_at' => date('Y-m-d H:i A', strtotime($this->created_at)),
            'created_at_iso' => $this->created_at ? \Illuminate\Support\Carbon::parse($this->created_at)->toIso8601String() : null,
        ];
    }
}
