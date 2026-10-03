<?php

namespace App\Http\Resources\Api\V2\Contract;

use App\Http\Resources\Api\V2\Contract\Concerns\MapsContractAddressFields;
use App\Http\Resources\Api\V2\Contract\Concerns\MapsContractStatusFields;
use App\Http\Resources\Concerns\WithContractDocumentationDeadline;
use App\Models\Contract;
use App\Support\DateInputNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class Step1Resource extends JsonResource
{
    use MapsContractAddressFields;
    use MapsContractStatusFields;
    use WithContractDocumentationDeadline;

    private function fileUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $normalized = str_starts_with($path, 'storage/') ? substr($path, 8) : $path;

        return getFilePath($normalized);
    }

    /**
     * @return array{day: ?string, month: ?string, year: ?string}
     */
    private function instrumentHistoryParts(): array
    {
        $value = $this->instrument_history;
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }
        $value = $value !== null ? (string) $value : null;

        return DateInputNormalizer::splitMysqlDate($value);
    }

    public function toArray(Request $request): array
    {
        $historyParts = $this->instrumentHistoryParts();
        $firstRegParts = $this->dateFirstRegistrationParts();

        return [
            'id' => $this->id,
            'contract_id' => $this->id,
            'uuid' => $this->uuid,
            'contract_type' => $this->contract_type,
            'contract_type_trans' => $this->contract_type_trans,
            'real_id' => $this->real_id,
            'real_units_id' => $this->real_units_id,
            'instrument_type' => $this->instrument_type,
            'instrument_type_trans' => $this->instrument_type_trans,
            ...Contract::instrumentTypeImageRequirements($this->instrument_type),
            'number_of_units_in_realestate' => $this->number_of_units_in_realestate,
            'number_of_floors' => $this->number_of_floors,
            'property_type_id' => $this->property_type_id,
            'property_usages_id' => $this->property_usages_id,
            // Deed/instrument images are private: temporary signed URLs, not public paths.
            'image_instrument' => \App\Support\DeedImage::signedUrl($this->resource, 'image_instrument'),
            'image_instrument_from_the_front' => \App\Support\DeedImage::signedUrl($this->resource, 'image_instrument_from_the_front'),
            'image_instrument_from_the_back' => \App\Support\DeedImage::signedUrl($this->resource, 'image_instrument_from_the_back'),
            'age_of_the_property' => $this->age_of_the_property,
            'number_of_units_per_floor' => $this->number_of_units_per_floor,
            'instrument_number' => $this->instrument_number,
            'instrument_history' => $this->instrument_history instanceof \DateTimeInterface
                ? $this->instrument_history->format('Y-m-d')
                : $this->instrument_history,
            'instrument_history_day' => $historyParts['day'],
            'instrument_history_month' => $historyParts['month'],
            'instrument_history_year' => $historyParts['year'],
            'type_instrument_history' => $this->type_instrument_history ?? 'hijri',
            'real_estate_registry_number' => $this->real_estate_registry_number,
            'date_first_registration' => $this->formatMysqlDate($this->date_first_registration),
            'date_first_registration_day' => $firstRegParts['day'],
            'date_first_registration_month' => $firstRegParts['month'],
            'date_first_registration_year' => $firstRegParts['year'],
            'type_date_first_registration' => $this->type_date_first_registration ?? 'hijri',
            'copy_of_the_endowment_registration_certificate' => \App\Support\DeedImage::signedUrl($this->resource, 'copy_of_the_endowment_registration_certificate'),
            'copy_of_the_trusteeship_deed' => \App\Support\DeedImage::signedUrl($this->resource, 'copy_of_the_trusteeship_deed'),
            'is_multiple_trusteeship_deed_copy' => (bool) $this->is_multiple_trusteeship_deed_copy,
            // Deceased-owner / representative-capacity documents (were uploaded but never returned).
            'Image_inheritance_certificate' => \App\Support\DeedImage::signedUrl($this->resource, 'Image_inheritance_certificate'),
            'copy_power_of_attorney_from_heirs_to_agent' => \App\Support\DeedImage::signedUrl($this->resource, 'copy_power_of_attorney_from_heirs_to_agent'),
            'copy_of_guardians_power_of_attorney_for_agent' => \App\Support\DeedImage::signedUrl($this->resource, 'copy_of_guardians_power_of_attorney_for_agent'),
            'Image_from_the_agency' => $this->fileUrl($this->Image_from_the_agency),
            'property_owner_is_deceased' => (bool) $this->property_owner_is_deceased,
            'latitude' => $lat = $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $lng = $this->longitude !== null ? (float) $this->longitude : null,
            'lat' => $lat,
            'lng' => $lng,
            ...$this->contractAddressFields(),
            ...$this->contractStatusFields(),
            'step' => $this->step,
        ];
    }

    /**
     * @return array{day: ?string, month: ?string, year: ?string}
     */
    private function dateFirstRegistrationParts(): array
    {
        return DateInputNormalizer::splitMysqlDate($this->formatMysqlDate($this->date_first_registration));
    }

    private function formatMysqlDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
