<?php

namespace App\Http\Resources\Admin\V2\Api\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrdersKpisResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'total' => (int) ($this->resource['total'] ?? 0),
            'new' => (int) ($this->resource['new'] ?? 0),
            'paid' => (int) ($this->resource['paid'] ?? 0),
            'draft' => (int) ($this->resource['draft'] ?? 0),
            'incomplete' => (int) ($this->resource['incomplete'] ?? 0),
            'canceled' => (int) ($this->resource['canceled'] ?? 0),
            'returned' => (int) ($this->resource['returned'] ?? 0),
            'refunded_orders' => (int) ($this->resource['refunded_orders'] ?? $this->resource['returned'] ?? 0),
            'avg_completion_minutes' => $this->resource['avg_completion_minutes'] ?? null,
        ];
    }
}
