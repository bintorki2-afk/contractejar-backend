<?php

namespace App\Http\Requests\Api\V2\RealEstate\Concerns;

use App\Http\Requests\Api\V2\Concerns\NormalizesCoordinateInputs;

trait RealEstateLocationRules
{
    use NormalizesCoordinateInputs;
    /**
     * @return array<string, mixed>
     */
    protected function locationRules(bool $requireId = false): array
    {
        $rules = [
            'property_place_id' => 'nullable|integer|exists:regions,id',
            'property_city_id' => 'nullable|integer|exists:cities,id',
            'neighborhood' => 'nullable|string|max:255',
            'street' => 'nullable|string|max:255',
            'building_number' => 'nullable|string|max:50',
            'postal_code' => 'nullable|string|max:20',
            'extra_figure' => 'nullable|string|max:255',
            'address_url' => 'nullable|string|max:2048',
            'image_address' => 'nullable|file',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
        ];

        if ($requireId) {
            $rules['id'] = 'required|exists:real_estates,id';
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function locationMessages(): array
    {
        return [
            'property_place_id.required' => 'المنطقة مطلوبة.',
            'property_place_id.exists' => 'المنطقة المحددة غير موجودة.',
            'property_city_id.required' => 'المدينة مطلوبة.',
            'property_city_id.exists' => 'المدينة المحددة غير موجودة.',
            'neighborhood.required' => 'الحي مطلوب.',
            'street.required' => 'الشارع مطلوب.',
            'building_number.required' => 'رقم المبنى مطلوب.',
            'postal_code.required' => 'الرمز البريدي مطلوب.',
            'extra_figure.required' => 'الرقم الإضافي مطلوب.',
            'latitude.numeric' => 'خط العرض يجب أن يكون رقماً.',
            'longitude.numeric' => 'خط الطول يجب أن يكون رقماً.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function locationAttributesForPayload(): array
    {
        $payload = [];

        foreach ([
            'property_place_id',
            'property_city_id',
            'neighborhood',
            'street',
            'building_number',
            'postal_code',
            'extra_figure',
            'address_url',
        ] as $field) {
            if ($this->exists($field)) {
                $payload[$field] = $this->input($field);
            }
        }

        if ($this->filled('latitude')) {
            $payload['latitude'] = $this->input('latitude');
        }

        if ($this->filled('longitude')) {
            $payload['longitude'] = $this->input('longitude');
        }

        // QA-F PROPS-6: الإحداثي من رابط قوقل ماب إن وُجد؛ والقيمة المبدئية (وسط الرياض) لا تُحفظ كموقع للعقار.
        $fromUrl = \App\Support\MapUrlCoordinates::fromUrl($this->input('address_url'));
        if ($fromUrl !== null) {
            [$payload['latitude'], $payload['longitude']] = $fromUrl;
        } elseif (\App\Support\MapUrlCoordinates::isPlaceholder($payload['latitude'] ?? null, $payload['longitude'] ?? null)) {
            $payload['latitude'] = null;
            $payload['longitude'] = null;
        }

        return $payload;
    }
}
