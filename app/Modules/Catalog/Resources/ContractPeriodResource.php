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
            // دفعة (و) — D3: مفتاح الزر الجاهز + رسوم التوثيق من الخادم (الواجهات لا تحسب).
            ...$this->pricingFields(),
        ];
    }

    /** @return array{duration_preset: string|null, doc_fee: float|null, doc_fee_label: string|null} */
    private function pricingFields(): array
    {
        $months = $this->months !== null ? (int) $this->months : null;
        $preset = $months !== null ? (array_search($months, \App\Support\DocFee::PRESETS, true) ?: null) : null;
        $type = (string) ($this->contract_type ?? '');
        if ($months === null || $months <= 0 || ! in_array($type, ['housing', 'commercial'], true)) {
            return ['duration_preset' => $preset, 'doc_fee' => null, 'doc_fee_label' => null];
        }
        $fee = \App\Support\DocFee::amount($months, $type);

        return [
            'duration_preset' => $preset,
            'doc_fee' => $fee,
            'doc_fee_label' => rtrim(rtrim(number_format($fee, 2, '.', ''), '0'), '.').' ر.س',
        ];
    }
}
