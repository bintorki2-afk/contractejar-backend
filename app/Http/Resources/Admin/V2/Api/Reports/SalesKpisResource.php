<?php

namespace App\Http\Resources\Admin\V2\Api\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesKpisResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'total_sales' => $this->resource['total_sales'] ?? 0,
            'payments_count' => (int) ($this->resource['payments_count'] ?? 0),
            'avg_order_value' => $this->resource['avg_order_value'] ?? 0,
            'discounts_used' => $this->resource['discounts_used'] ?? 0,
            'refunds' => $this->resource['refunds'] ?? 0,
            'net_revenue' => $this->resource['net_revenue'] ?? 0,
            // دفعة (هـ) — 2.7
            'extra_fees' => $this->resource['extra_fees'] ?? 0,
            'extra_fees_count' => (int) ($this->resource['extra_fees_count'] ?? 0),
            'price_differences' => $this->resource['price_differences'] ?? 0,
            'price_differences_count' => (int) ($this->resource['price_differences_count'] ?? 0),
            'original_revenue' => $this->resource['original_revenue'] ?? ($this->resource['total_sales'] ?? 0),
            'bank_transfers' => $this->resource['bank_transfers'] ?? 0,
            'bank_transfers_count' => (int) ($this->resource['bank_transfers_count'] ?? 0),
            'lessor_change_sales' => $this->resource['lessor_change_sales'] ?? 0,
            'lessor_change_count' => (int) ($this->resource['lessor_change_count'] ?? 0),
        ];
    }
}
