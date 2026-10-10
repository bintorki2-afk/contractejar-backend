<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * دفعة (و) — D7: تقييمات العملاء المعروضة في الموقع والتطبيق (تُدار من «التسويق والمحتوى»).
 */
class CustomerReview extends Model
{
    use \App\Models\Concerns\FlushesPublicCache;

    public const CONTRACT_TYPES = ['residential', 'commercial'];

    protected $fillable = ['name', 'city', 'text', 'rating', 'contract_type', 'sort_order', 'is_visible'];

    protected $casts = [
        'rating' => 'integer',
        'sort_order' => 'integer',
        'is_visible' => 'boolean',
    ];

    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'city' => $this->city,
            'text' => $this->text,
            'rating' => (int) $this->rating,
            'contract_type' => $this->contract_type,
            'sort_order' => (int) $this->sort_order,
        ];
    }

    /** @return array<string, mixed> */
    public function toAdminArray(): array
    {
        return array_merge($this->toPublicArray(), [
            'is_visible' => (bool) $this->is_visible,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ]);
    }
}
