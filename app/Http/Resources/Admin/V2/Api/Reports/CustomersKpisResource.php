<?php

namespace App\Http\Resources\Admin\V2\Api\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomersKpisResource extends JsonResource
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
            'active' => (int) ($this->resource['active'] ?? 0),
            'returning' => (int) ($this->resource['returning'] ?? 0),
            'avg_contracts_per_customer' => $this->resource['avg_contracts_per_customer'] ?? 0,
            'incomplete' => (int) ($this->resource['incomplete'] ?? 0),
        ];
    }
}
