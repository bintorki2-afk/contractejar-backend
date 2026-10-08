<?php

namespace App\Modules\Users\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource === null) {
            return [];
        }

        $userId = $this->id;

        return [
            'id' => $userId,
            'fname' => $this->fname,
            'full_name' => $this->name,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'photo' => $this->photo_path,
            'verified' => $this->isVerified(),
            'name' => $this->name,
            'phone' => $this->mobile,
            'status' => $this->is_active == 1,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'date_time' => $this->created_at_label,
            'properties_count' => (int) ($this->real_estate_count ?? $this->realEstate?->count() ?? 0),
            'units_count' => (int) ($this->units_count ?? $this->unitReal?->count() ?? 0),
            'completed_orders_count' => (int) ($this->contracts?->where('is_delete', 0)->where('step', '>=', \App\Models\Contract::CUSTOMER_VISIBLE_MIN_STEP)->where('is_completed', 1)->count() ?? 0),
            'incomplete_orders_count' => (int) ($this->contracts?->where('is_delete', 0)->where('step', '>=', \App\Models\Contract::CUSTOMER_VISIBLE_MIN_STEP)->where('is_completed', 0)->count() ?? 0),
            'total_paid_amount' => $userId === null ? 0.0 : round((float) DB::table('payments')
                ->join('contracts', 'payments.contract_uuid', '=', 'contracts.uuid')
                ->where('contracts.user_id', $userId)
                ->where('payments.status', 'success')
                ->sum('payments.amount'), 2),
        ];
    }
}
