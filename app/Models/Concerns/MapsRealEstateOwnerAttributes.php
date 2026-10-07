<?php

namespace App\Models\Concerns;


trait MapsRealEstateOwnerAttributes
{
    /**
     * API / contract-aligned owner field names mapped to legacy real_estates columns.
     *
     * @return array<string, string>
     */
    public static function realEstateOwnerColumnAliases(): array
    {
        return [
            'property_owner_id_num' => 'national_num',
            'property_owner_dob_hijri' => 'dob_hijri',
            'property_owner_mobile' => 'mobile',
            'property_owner_iban' => 'iban_bank',
        ];
    }

    /**
     * Persist owner fields using columns that exist on real_estates (new names and/or legacy).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function mapOwnerAttributesForDatabase(array $attributes): array
    {
        // SchemaCache (قابل للتفريغ في الاختبارات) بدل static محلي يعلق بمخطط قديم.
        $columns = \App\Support\SchemaCache::columns('real_estates');

        $mapped = [];

        foreach ($attributes as $key => $value) {
            if (in_array($key, $columns, true)) {
                $mapped[$key] = $value;
            }

            $legacy = static::realEstateOwnerColumnAliases()[$key] ?? null;
            if ($legacy !== null && in_array($legacy, $columns, true)) {
                $mapped[$legacy] = $value;
            }
        }

        return $mapped;
    }

    public function getPropertyOwnerIdNumAttribute(mixed $value): mixed
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        return $this->attributes['national_num'] ?? null;
    }

    public function getPropertyOwnerDobHijriAttribute(mixed $value): mixed
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        return $this->attributes['dob_hijri'] ?? $this->attributes['DOB'] ?? null;
    }

    public function getPropertyOwnerMobileAttribute(mixed $value): mixed
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        return $this->attributes['mobile'] ?? null;
    }

    public function getPropertyOwnerIbanAttribute(mixed $value): mixed
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        return $this->attributes['iban_bank'] ?? null;
    }

    public function fill(array $attributes)
    {
        return parent::fill(static::mapOwnerAttributesForDatabase($attributes));
    }
}
