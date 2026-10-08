<?php

namespace App\Modules\Users\Resources\Admin;

use App\Http\Resources\Admin\V2\Api\OrderResource;
use App\Http\Resources\Api\V2\UnitResource;
use App\Http\Resources\RealEstateResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AllUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $paid = round((float) ($this->total_paid_amount ?? 0), 2);
        $refunded = round((float) ($this->total_refunded_amount ?? 0), 2);
        $completed = (int) ($this->completed_orders_count
            ?? $this->contracts?->where('is_completed', 1)->count()
            ?? 0);
        $draft = (int) ($this->draft_orders_count
            ?? $this->contracts?->where('is_draft', true)->count()
            ?? 0);
        $incomplete = (int) ($this->incomplete_orders_count
            ?? $this->contracts?->where('is_completed', 0)->count()
            ?? 0);
        $ordersCount = (int) ($this->orders_count ?? ($completed + $incomplete));
        $incompleteDrafts = (int) ($this->incomplete_drafts_count ?? 0);
        $realEstateCount = (int) ($this->real_estate_count
            ?? $this->realEstate?->count()
            ?? 0);
        $unitsCount = (int) ($this->units_count
            ?? $this->unitReal?->count()
            ?? 0);

        $platform = $this->resolvedPlatform();

        return [
            'id' => $this->id,
            'customer_number' => $this->customerNumber(),
            'full_name' => $this->name,
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'phone' => $this->mobile,
            'verified' => $this->isVerified(),
            'status' => (bool) $this->is_active,
            'is_active' => (bool) $this->is_active,
            'is_banned' => ! (bool) $this->is_active,
            'platform' => $platform,
            'platform_label' => $this->platformLabelAr(),
            'joined_at' => $this->created_at?->format('Y-m-d H:i'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'date_time' => $this->created_at_label,
            'completed' => $completed,
            'completed_orders_count' => $completed,
            'draft' => $draft,
            'draft_orders_count' => $draft,
            'uncompleted_orders_count' => $incomplete,
            'incomplete_orders_count' => $incomplete,
            // ب5: نفس عدّاد «جميع الطلبات?user_id=» + المسودات المبكرة (تبويب «غير مكتمل»).
            'orders_count' => $ordersCount,
            'incomplete_drafts_count' => $incompleteDrafts,
            'real_estates' => $realEstateCount,
            'real_estate_count' => $realEstateCount,
            'properties_count' => $realEstateCount,
            'units' => $unitsCount,
            'units_count' => $unitsCount,
            'refunded' => $refunded,
            'refunded_amount' => $refunded,
            'paid' => $paid,
            'total_paid_amount' => $paid,
            'net' => round($paid - $refunded, 2),
            'net_amount' => round($paid - $refunded, 2),
            'photo_path' => $this->photo_path ? url("storage/{$this->photo_path}") : null,
            'contracts' => $this->when(
                $this->relationLoaded('contracts'),
                fn () => OrderResource::collection($this->contracts)
            ),
            'real_estates_list' => $this->when(
                $this->relationLoaded('realEstate'),
                fn () => RealEstateResource::collection($this->realEstate)
            ),
            'units_list' => $this->when(
                $this->relationLoaded('unitReal'),
                fn () => UnitResource::collection($this->unitReal)
            ),
        ];
    }
}
