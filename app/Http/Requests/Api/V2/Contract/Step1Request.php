<?php

namespace App\Http\Requests\Api\V2\Contract;

use App\Http\Requests\Api\V2\BaseApiV2Request;
use App\Http\Requests\Api\V2\Concerns\ContractPropertyAddressRules;
use App\Http\Requests\Api\V2\Concerns\NormalizesCoordinateInputs;
use App\Http\Requests\Api\V2\Concerns\ResolvesContractIdInput;
use App\Models\City;
use App\Models\Contract;
use App\Models\RealEstate;
use App\Support\DateInputNormalizer;
use App\Support\HijriDobParts;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class Step1Request extends BaseApiV2Request
{
    use ContractPropertyAddressRules;
    use NormalizesCoordinateInputs;
    use ResolvesContractIdInput;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // التطبيق يرسل القيم المنطقية كنصوص 'true'/'false' في multipart — قاعدة boolean لا تقبلها.
        foreach (['is_multiple_trusteeship_deed_copy'] as $boolKey) {
            if ($this->has($boolKey) && is_string($this->input($boolKey))) {
                $normalized = filter_var($this->input($boolKey), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($normalized !== null) {
                    $this->merge([$boolKey => $normalized ? 1 : 0]);
                }
            }
        }
        $this->resolveContractIdInput();
        $this->normalizeCoordinateInputs();

        if ($this->has('instrument_type')) {
            $this->merge([
                'instrument_type' => Contract::normalizeInstrumentType($this->input('instrument_type')),
            ]);
        }

        // Split combined instrument_history into day/month/year when parts are absent.
        if (! $this->filled('instrument_history_day') && $this->filled('instrument_history')) {
            $raw = trim((string) $this->input('instrument_history'));
            $parts = DateInputNormalizer::splitMysqlDate($raw);
            if ($parts['day'] === null) {
                $parts = HijriDobParts::split($raw);
            }

            if ($parts['day'] !== null && $parts['month'] !== null && $parts['year'] !== null) {
                $this->merge([
                    'instrument_history_day' => (int) $parts['day'],
                    'instrument_history_month' => (int) $parts['month'],
                    'instrument_history_year' => (int) $parts['year'],
                ]);
            }
        }

        // Same for date_first_registration (سجل عقاري / strong_argument).
        if (! $this->filled('date_first_registration_day') && $this->filled('date_first_registration')) {
            $raw = trim((string) $this->input('date_first_registration'));
            $parts = DateInputNormalizer::splitMysqlDate($raw);
            if ($parts['day'] === null) {
                $parts = HijriDobParts::split($raw);
            }

            if ($parts['day'] !== null && $parts['month'] !== null && $parts['year'] !== null) {
                $this->merge([
                    'date_first_registration_day' => (int) $parts['day'],
                    'date_first_registration_month' => (int) $parts['month'],
                    'date_first_registration_year' => (int) $parts['year'],
                ]);
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|exists:contracts,id',
            'instrument_type' => ['nullable', Rule::in(Contract::instrumentTypes())],
            'number_of_floors' => 'nullable',
            'property_type_id' => ['nullable', Rule::exists('rea_estat_types', 'id')],
            'property_usages_id' => ['nullable', Rule::exists('rea_estat_usages', 'id')],
            'image_instrument' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:10240',
            ],
            // صفحات إضافية للصك (الصك الورقي متعدد الصفحات) — حتى 10 ملفات.
            'image_instrument_pages' => ['nullable', 'array', 'max:10'],
            'image_instrument_pages.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'image_address' => 'nullable|image|max:10240',
            'address_url' => 'nullable|string|max:2048',
            'instrument_number' => 'nullable|string|max:255',
            'instrument_history' => 'nullable|string|max:32',
            'instrument_history_day' => 'nullable|integer|min:1|max:31',
            'instrument_history_month' => 'nullable|integer|min:1|max:12',
            'instrument_history_year' => 'nullable|integer|min:1',
            'type_instrument_history' => 'nullable|in:hijri,gregorian',
            'real_estate_registry_number' => 'nullable|string|max:255',
            'date_first_registration' => 'nullable|string|max:32',
            'date_first_registration_day' => 'nullable|integer|min:1|max:31',
            'date_first_registration_month' => 'nullable|integer|min:1|max:12',
            'date_first_registration_year' => 'nullable|integer|min:1',
            'type_date_first_registration' => 'nullable|in:hijri,gregorian',
            'age_of_the_property' => 'nullable|integer|min:0',
            'number_of_units_per_floor' => 'nullable|string|max:255',
            'number_of_units_in_realestate' => 'nullable|string|max:255',
            'copy_of_the_endowment_registration_certificate' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'copy_of_the_trusteeship_deed' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'is_multiple_trusteeship_deed_copy' => 'nullable|boolean',
            'Image_inheritance_certificate' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'copy_power_of_attorney_from_heirs_to_agent' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'copy_of_guardians_power_of_attorney_for_agent' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'image_instrument_from_the_back' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:10240',
            ],
            'image_instrument_from_the_front' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:10240',
            ],
            ...$this->contractPropertyAddressRules(),
        ];
    }

    public function messages(): array
    {
        return $this->contractV2ArabicMessages([
            'id',
            'instrument_type',
            'number_of_floors',
            'property_type_id',
            'property_usages_id',
            'number_of_units_in_realestate',
            'number_of_units_per_floor',
            'image_instrument',
            'image_address',
            'address_url',
            'image_instrument_from_the_front',
            'image_instrument_from_the_back',
            'instrument_number',
            'instrument_history',
            'instrument_history_day',
            'instrument_history_month',
            'instrument_history_year',
            'type_instrument_history',
            'real_estate_registry_number',
            'date_first_registration',
            'date_first_registration_day',
            'date_first_registration_month',
            'date_first_registration_year',
            'type_date_first_registration',
            'age_of_the_property',
            'copy_of_the_endowment_registration_certificate',
            'copy_of_the_trusteeship_deed',
            'is_multiple_trusteeship_deed_copy',
            'Image_inheritance_certificate',
            'copy_power_of_attorney_from_heirs_to_agent',
            'copy_of_guardians_power_of_attorney_for_agent',
            'latitude',
            'longitude',
            'lat',
            'lng',
            'property_place_id',
            'property_city_id',
            'neighborhood',
            'street',
            'building_number',
            'postal_code',
            'extra_figure',
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('property_city_id') && $this->filled('property_place_id')) {
                $city = City::query()
                    ->whereKey($this->input('property_city_id'))
                    ->where('region_id', $this->input('property_place_id'))
                    ->exists();

                if (! $city) {
                    $validator->errors()->add('property_city_id', trans('api.city_not_include_region'));
                }
            }

            if (! in_array($this->input('instrument_type'), ['electronic', 'strong_argument'], true)) {
                return;
            }

            $contract = Contract::query()->find($this->input('id'));
            if (! $contract || ! $contract->real_id) {
                return;
            }

            $real = RealEstate::query()->find($contract->real_id);
            if (! $real || ! $real->hasResolvableUnitsCount($contract->real_units_id)) {
                $validator->errors()->add(
                    'number_of_units_in_realestate',
                    'عدد الوحدات غير محدد في العقار المرتبط بالعقد.'
                );
            }
        });
    }

    /**
     * Build instrument_history for storage from day/month/year or combined string.
     * Stored as YYYY-MM-DD (MySQL DATE). type_instrument_history keeps calendar meaning.
     */
    public function resolvedInstrumentHistory(): ?string
    {
        return $this->resolveDateField(
            'instrument_history',
            'instrument_history_day',
            'instrument_history_month',
            'instrument_history_year'
        );
    }

    /**
     * Build date_first_registration for storage (same rules as instrument_history).
     */
    public function resolvedDateFirstRegistration(): ?string
    {
        return $this->resolveDateField(
            'date_first_registration',
            'date_first_registration_day',
            'date_first_registration_month',
            'date_first_registration_year'
        );
    }

    private function resolveDateField(string $combined, string $dayKey, string $monthKey, string $yearKey): ?string
    {
        $hasDay = $this->filled($dayKey);
        $hasMonth = $this->filled($monthKey);
        $hasYear = $this->filled($yearKey);

        if ($hasDay && $hasMonth && $hasYear) {
            return DateInputNormalizer::combineFromParts(
                $this->input($dayKey),
                $this->input($monthKey),
                $this->input($yearKey),
            );
        }

        if ($this->filled($combined)) {
            $raw = trim((string) $this->input($combined));
            if ($raw === '') {
                return null;
            }

            $mysql = DateInputNormalizer::toMysqlDate($raw);
            if ($mysql !== null) {
                return $mysql;
            }

            $parts = preg_split('/[-\/]/', $raw);
            if (count($parts) === 3) {
                return DateInputNormalizer::combineFromParts($parts[0], $parts[1], $parts[2]);
            }

            return null;
        }

        return null;
    }
}
