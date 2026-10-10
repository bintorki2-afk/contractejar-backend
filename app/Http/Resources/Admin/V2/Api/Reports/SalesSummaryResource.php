<?php

namespace App\Http\Resources\Admin\V2\Api\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesSummaryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'discounts_granted' => $this->resource['discounts_granted'] ?? 0,
            'discounted_orders_count' => (int) ($this->resource['discounted_orders_count'] ?? 0),
            'refunds_total' => $this->resource['refunds_total'] ?? 0,
            'refund_rate_percent' => (int) ($this->resource['refund_rate_percent'] ?? 0),
            'net_revenue_after_refunds' => $this->resource['net_revenue_after_refunds'] ?? 0,
            // دفعة (هـ) — 2.7
            'extra_fees' => $this->resource['extra_fees'] ?? 0,
            'price_differences' => $this->resource['price_differences'] ?? 0,
            'refunds' => $this->resource['refunds'] ?? ($this->resource['refunds_total'] ?? 0),
            'net_revenue' => $this->resource['net_revenue'] ?? ($this->resource['net_revenue_after_refunds'] ?? 0),
        ];
    }
}
