<?php

namespace App\Http\Resources\Admin\V2\Api\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerformanceKpisResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $total = (int) ($this->resource['total'] ?? $this->resource['total_count'] ?? 0);
        $active = (int) ($this->resource['active_count'] ?? $this->resource['working_count'] ?? 0);

        return [
            'total_count' => (int) ($this->resource['total_count'] ?? $total),
            'total' => $total,
            'documented_count' => (int) ($this->resource['documented_count'] ?? 0),
            'working_count' => (int) ($this->resource['working_count'] ?? $active),
            'active_count' => $active,
            'canceled_count' => (int) ($this->resource['canceled_count'] ?? 0),
            'refunded_count' => (int) ($this->resource['refunded_count'] ?? 0),
            'refunded_orders' => (int) ($this->resource['refunded_orders'] ?? $this->resource['refunded_count'] ?? 0),
            'refund_requests_confirmed' => (int) ($this->resource['refund_requests_confirmed'] ?? 0),
            'revenue' => $this->resource['revenue'] ?? 0,
            // دفعة (هـ) — 2.7
            'extra_fees' => $this->resource['extra_fees'] ?? 0,
            'price_differences' => $this->resource['price_differences'] ?? 0,
            'refunds' => $this->resource['refunds'] ?? 0,
            'net_revenue' => $this->resource['net_revenue'] ?? ($this->resource['revenue'] ?? 0),
            'paid' => (int) ($this->resource['paid'] ?? 0),
            'delayed_count' => (int) ($this->resource['delayed_count'] ?? 0),
        ];
    }
}
