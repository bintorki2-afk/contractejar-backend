<?php

namespace App\Modules\Catalog\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period' => $this->period,
            'note' => $this->note_trans,
            'months' => $this->months !== null ? (int) $this->months : null,
            'is_active' => (bool) ($this->is_active ?? true),
        ];
    }
}
