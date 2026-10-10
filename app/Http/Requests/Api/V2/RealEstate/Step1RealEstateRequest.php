<?php

namespace App\Http\Requests\Api\V2\RealEstate;

use App\Http\Requests\Api\V2\BaseApiV2Request;
use App\Http\Requests\Api\V2\RealEstate\Concerns\NormalizesRealEstateInstrumentType;
use App\Http\Requests\Api\V2\RealEstate\Concerns\RealEstateLocationRules;
use App\Models\City;
use App\Models\RealEstate;
use App\Support\DateInputNormalizer;
use App\Support\HijriDobParts;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * V2 real-estate step 1: same shape as {@see \App\Http\Requests\Api\V2\Contract\Step1Request}
 * but for creating a {@see RealEstate} (no contract id).
 */
class Step1RealEstateRequest extends BaseApiV2Request
{
    use NormalizesRealEstateInstrumentType;
    use RealEstateLocationRules;

    protected function prepareForValidation(): void
    {
        $this->normalizeCoordinateInputs();
        $this->normalizeInstrumentTypeInput();
        // QA-F PROPS-5: الموقع يسمّي السكني residential.
        if (in_array(strtolower((string) $this->input('contract_type')), ['residential', 'residence'], true)) {
            $this->merge(['contract_type' => 'housing']);
        }
        $this->normalizeDateFirstRegistrationParts();
    }

    private function normalizeDateFirstRegistrationParts(): void
    {
        if ($this->filled('date_first_registration_day') || ! $this->filled('date_first_registration')) {
            return;
        }

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

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $instrumentType = $this->input('instrument_type');
        $ownerEndowment = RealEstate::INSTRUMENT_TYPE_OWNER_ENDOWMENT;

        return array_merge([
            'real_id'            => 'nullable|exists:contracts,id',
            'instrument_type'    => ['nullable', Rule::in(RealEstate::instrumentTypes())],
            'number_of_floors'   => 'nullable',
            'contract_type'    => 'nullable|in:housing,commercial',
            'contract_ownership' => 'nullable|in:owner,tenant',
            'electricity_meter_ownership' => 'nullable|in:owner,tenant',
            'water_meter_ownership' => 'nullable|in:owner,tenant',
            'property_type_id'   => 'nullable|exists:rea_estat_types,id',
            'property_usages_id' => 'nullable|exists:rea_estat_usages,id',

            'image_instrument'   => [
                'nullable',
                'file',
                Rule::requiredIf(
                    $instrumentType === $ownerEndowment
                    || $this->requiresElectronicDeedImage()
                ),
            ],

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
            'age_of_the_property'            => 'nullable|integer|min:0',
            'number_of_units_per_floor'      => 'nullable|string|max:255',
            'number_of_units_in_realestate'  => 'nullable|string|max:255',
            'copy_of_the_endowment_registration_certificate' => [
                'nullable',
                'file',
                Rule::requiredIf($instrumentType === $ownerEndowment),
            ],
            'copy_of_the_trusteeship_deed' => [
                'nullable',
                'file',
                Rule::requiredIf($instrumentType === $ownerEndowment),
            ],
            'is_multiple_trusteeship_deed_copy' => 'nullable|boolean',
            'copy_of_guardians_power_of_attorney_for_agent' => [
                'nullable',
                'file',
                Rule::requiredIf(function () use ($instrumentType, $ownerEndowment) {
                    return $instrumentType === $ownerEndowment
                        && $this->boolean('is_multiple_trusteeship_deed_copy');
                }),
            ],
        ], $this->locationRules());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $this->filled('property_city_id') || ! $this->filled('property_place_id')) {
                return;
            }

            $valid = City::query()
                ->where('id', $this->input('property_city_id'))
                ->where('region_id', $this->input('property_place_id'))
                ->exists();

            if (! $valid) {
                $validator->errors()->add('property_city_id', trans('api.city_not_include_region'));
            }
        });
    }

    public function messages(): array
    {
        return array_merge([
            'property_type_id.exists'                 => 'نوع العقار غير موجود.',
            'property_usages_id.exists'               => 'استخدام العقار غير موجود.',
            'contract_type.required'                  => 'نوع العقد مطلوب.',
            'contract_type.in'                        => 'نوع العقد يجب أن يكون سكني أو تجاري.',
            'instrument_type.in'                      => 'نوع الصك غير صالح.',
            'number_of_units_in_realestate.string'    => 'عدد الوحدات يجب أن يكون نصًا.',
            'image_instrument.required'               => 'صورة الصك مطلوبة عند اختيار صك إلكتروني، ما لم يتم إدخال بيانات الصك يدويًا.',
            'copy_of_the_endowment_registration_certificate.required' => 'صورة من شهادة تسجيل الوقف مطلوبة.',
            'copy_of_the_trusteeship_deed.required' => 'صورة من صك النظارة مطلوبة.',
            'copy_of_guardians_power_of_attorney_for_agent.required' => 'صورة من وكالة النظار للوكيل مطلوبة عند وجود أكثر من ناظر.',
        ], $this->locationMessages());
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesForCreate(int $userId): array
    {
        $payload = array_merge([
            'user_id'                        => $userId,
            'instrument_number'              => $this->input('instrument_number'),
            'number_of_units_in_realestate'  => $this->input('number_of_units_in_realestate'),
            'property_type_id'               => $this->input('property_type_id'),
            'property_usages_id'             => $this->input('property_usages_id'),
            'number_of_floors'               => $this->input('number_of_floors'),
            'age_of_the_property'            => $this->input('age_of_the_property'),
            'number_of_units_per_floor'      => $this->input('number_of_units_per_floor'),
            'step'                           => 1,
        ], $this->locationAttributesForPayload());

        if ($this->filled('contract_type')) {
            $payload['contract_type'] = $this->input('contract_type');
        }

        if ($this->filled('instrument_type')) {
            $payload['instrument_type'] = $this->input('instrument_type');
        }

        if ($this->filled('contract_ownership')) {
            $payload['contract_ownership'] = $this->input('contract_ownership');
        }

        if ($this->exists('electricity_meter_ownership')) {
            $value = $this->input('electricity_meter_ownership');
            $payload['electricity_meter_ownership'] = ($value === '' || $value === null) ? null : $value;
        }

        if ($this->exists('water_meter_ownership')) {
            $value = $this->input('water_meter_ownership');
            $payload['water_meter_ownership'] = ($value === '' || $value === null) ? null : $value;
        }

         if ($this->input('instrument_type') === 'electronic') {
            $history = $this->resolvedInstrumentHistory();
            if ($history !== null) {
                $payload['instrument_history'] = $history;
                $payload['type_instrument_history'] = $this->input('type_instrument_history', 'hijri');
            }
        }

        if ($this->filled('real_estate_registry_number')) {
            $payload['real_estate_registry_number'] = $this->input('real_estate_registry_number');
        }

        $dateFirst = $this->resolvedDateFirstRegistration();
        if ($dateFirst !== null) {
            $payload['date_first_registration'] = $dateFirst;
            $payload['type_date_first_registration'] = $this->input('type_date_first_registration', 'hijri');
        }

        // Sensitive real-estate documents go to the PRIVATE disk and are served only
        // through signed URLs (see App\Support\RealEstateImage) — never world-readable.
        $privateDisk = \App\Support\RealEstateImage::DISK;

        if ($this->hasFile('image_instrument')) {
            $payload['image_instrument'] = $this->file('image_instrument')
                ->store('images/real_estates', $privateDisk);
        }

        if ($this->hasFile('image_address')) {
            $payload['image_address'] = $this->file('image_address')
                ->store('images/real_estates', $privateDisk);
        }

        if ($this->input('instrument_type') === RealEstate::INSTRUMENT_TYPE_OWNER_ENDOWMENT) {
            $payload['is_multiple_trusteeship_deed_copy'] = $this->boolean('is_multiple_trusteeship_deed_copy');
            if ($this->hasFile('copy_of_the_endowment_registration_certificate')) {
                $payload['copy_of_the_endowment_registration_certificate'] = $this->file('copy_of_the_endowment_registration_certificate')
                    ->store('real_estates/endowment-registration-certificates', $privateDisk);
            }
            if ($this->hasFile('copy_of_the_trusteeship_deed')) {
                $payload['copy_of_the_trusteeship_deed'] = $this->file('copy_of_the_trusteeship_deed')
                    ->store('real_estates/trusteeship-deeds', $privateDisk);
            }
            if ($this->hasFile('copy_of_guardians_power_of_attorney_for_agent')) {
                $payload['copy_of_guardians_power_of_attorney_for_agent'] = $this->file('copy_of_guardians_power_of_attorney_for_agent')
                    ->store('real_estates/guardians-power-of-attorney', $privateDisk);
            }
        }

        return $payload;
    }

    public function resolvedInstrumentHistory(): ?string
    {
        return $this->resolveDateField(
            'instrument_history',
            'instrument_history_day',
            'instrument_history_month',
            'instrument_history_year'
        );
    }

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
        }

        return null;
    }
}