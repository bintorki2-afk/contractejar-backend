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
        // return parent::toArray($request);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'is_read' => (bool) $this->is_read,
            // ربط الإشعار بالطلب: يفتح التطبيق/الموقع الطلب مباشرة بدل البحث بالنص.
            'contract_id' => $this->contract_id,
            'contract_uuid' => $this->contract_id ? (string) optional($this->contract)->uuid : null,
            'order_number' => $this->contract_id ? (string) optional($this->contract)->uuid : null,
            'smart_link' => $this->contract_id && $this->contract ? \App\Support\SmartLink::for($this->contract) : null,
            'created_at' => date('Y-m-d H:i A', strtotime($this->created_at))
        ];
    }
}
