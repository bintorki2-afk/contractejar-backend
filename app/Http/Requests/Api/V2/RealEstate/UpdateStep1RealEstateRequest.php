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

class UpdateStep1RealEstateRequest extends BaseApiV2Request
{
    use NormalizesRealEstateInstrumentType;
    use RealEstateLocationRules;

    protected function prepareForValidation(): void
    {
        $this->normalizeCoordinateInputs();
        $this->normalizeInstrumentTypeInput();

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
        $instrumentTypes = RealEstate::instrumentTypes();
        $instrumentType = $this->input('instrument_type');

        return array_merge([
            'id' => 'required|exists:real_estates,id',
            'name_real_estate' => 'nullable|string|max:255',
            'contract_ownership' => 'nullable|in:owner,tenant',
            'electricity_meter_ownership' => 'nullable|in:owner,tenant',
            'water_meter_ownership' => 'nullable|in:owner,tenant',
            'contract_type' => 'nullable|in:housing,commercial',
            'property_owner_is_deceased' => 'nullable|boolean',
            'instrument_history' => 'nullable|string|max:32',
            'instrument_history_day' => 'nullable|integer|min:1|max:31',
            'instrument_history_month' => 'nullable|integer|min:1|max:12',
            'instrument_history_year' => 'nullable|integer|min:1',
            'real_estate_registry_number' => [Rule::requiredIf($instrumentType === 'strong_argument')],
            'date_first_registration' => [
                Rule::requiredIf($instrumentType === 'strong_argument'),
                'nullable',
                'string',
                'max:32',
            ],
            'date_first_registration_day' => 'nullable|integer|min:1|max:31',
            'date_first_registration_month' => 'nullable|integer|min:1|max:12',
            'date_first_registration_year' => 'nullable|integer|min:1',
            'property_type_id' => 'nullable|exists:rea_estat_types,id',
            'number_of_floors' => 'nullable',
            'instrument_type' => ['nullable', Rule::in($instrumentTypes), 'required_if:property_owner_is_deceased,1'],
            'property_usages_id' => 'nullable|exists:rea_estat_usages,id',
            'number_of_units_in_realestate' => 'nullable|integer',
            'image_instrument' => 'nullable|file',
            'instrument_number' => 'nullable|string|max:255',
            'image_address' => 'nullable|file',
            'age_of_the_property' => 'nullable|integer|min:0',
            'number_of_units_per_floor' => 'nullable|string|max:255',
            'type_instrument_history' => 'nullable|in:hijri,gregorian',
            'type_date_first_registration' => 'nullable|in:hijri,gregorian',
            'copy_of_the_endowment_registration_certificate' => 'nullable|file',
            'copy_of_the_trusteeship_deed' => 'nullable|file',
            'is_multiple_trusteeship_deed_copy' => 'nullable|boolean',
            'copy_of_guardians_power_of_attorney_for_agent' => 'nullable|file',
        ], $this->locationRules(requireId: false));
    }

    public function messages(): array
    {
        return array_merge([
            'id.required' => 'معرف العقار مطلوب.',
            'id.exists' => 'العقار المحدد غير موجود.',
        ], $this->locationMessages());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->filled('property_city_id') && $this->filled('property_place_id')) {
                $valid = City::query()
                    ->where('id', $this->input('property_city_id'))
                    ->where('region_id', $this->input('property_place_id'))
                    ->exists();

                if (! $valid) {
                    $validator->errors()->add('property_city_id', trans('api.city_not_include_region'));
                }
            }

            $instrumentType = $this->input('instrument_type');
            $ownerEndowment = RealEstate::INSTRUMENT_TYPE_OWNER_ENDOWMENT;

            $real = RealEstate::query()->find($this->input('id'));
            if (! $real) {
                return;
            }

            if ($this->requiresElectronicDeedImage()
                && ! $this->hasFile('image_instrument')
                && empty($real->image_instrument)
                && empty($real->instrument_number)) {
                $validator->errors()->add('image_instrument', 'صورة الصك مطلوبة عند اختيار صك إلكتروني، ما لم يتم إدخال بيانات الصك يدويًا.');
            }

            if ($instrumentType === $ownerEndowment) {
                $checks = [
                    'image_instrument' => [$real->image_instrument, 'صورة الصك مطلوبة.'],
                    'copy_of_the_endowment_registration_certificate' => [
                        $real->copy_of_the_endowment_registration_certificate,
                        'صورة من شهادة تسجيل الوقف مطلوبة.',
                    ],
                    'copy_of_the_trusteeship_deed' => [$real->copy_of_the_trusteeship_deed, 'صورة من صك النظارة مطلوبة.'],
                ];
                foreach ($checks as $field => [$stored, $message]) {
                    if (! $this->hasFile($field) && empty($stored)) {
                        $validator->errors()->add($field, $message);
                    }
                }

                if ($this->boolean('is_multiple_trusteeship_deed_copy')
                    && ! $this->hasFile('copy_of_guardians_power_of_attorney_for_agent')
                    && empty($real->copy_of_guardians_power_of_attorney_for_agent)) {
                    $validator->errors()->add(
                        'copy_of_guardians_power_of_attorney_for_agent',
                        'صورة من وكالة النظار للوكيل مطلوبة عند وجود أكثر من ناظر.'
                    );
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesForUpdate(): array
    {
        $data = array_merge([
            'name_real_estate' => $this->input('name_real_estate'),
            'real_estate_registry_number' => $this->input('real_estate_registry_number'),
            'step' => 1,
        ], $this->locationAttributesForPayload());

        $dateFirst = $this->resolvedDateFirstRegistration();
        if ($dateFirst !== null) {
            $data['date_first_registration'] = $dateFirst;
            $data['type_date_first_registration'] = $this->input('type_date_first_registration', 'hijri');
        }

        if ($this->exists('property_owner_is_deceased')) {
            $data['property_owner_is_deceased'] = $this->input('property_owner_is_deceased') === null
                ? null
                : $this->boolean('property_owner_is_deceased');
        }

        foreach ([
            'property_type_id',
            'property_usages_id',
            'number_of_floors',
            'age_of_the_property',
            'number_of_units_per_floor',
            'number_of_units_in_realestate',
        ] as $optionalField) {
            if ($this->exists($optionalField)) {
                $data[$optionalField] = $this->input($optionalField);
            }
        }

        if ($this->filled('contract_type')) {
            $data['contract_type'] = $this->input('contract_type');
        }

        if ($this->filled('instrument_type')) {
            $data['instrument_type'] = $this->input('instrument_type');
        }

        if ($this->filled('contract_ownership')) {
            $data['contract_ownership'] = $this->input('contract_ownership');
        }

        foreach (['electricity_meter_ownership', 'water_meter_ownership'] as $ownership) {
            if ($this->exists($ownership)) {
                $value = $this->input($ownership);
                $data[$ownership] = ($value === '' || $value === null) ? null : $value;
            }
        }

        if ($this->input('instrument_type') === RealEstate::INSTRUMENT_TYPE_OWNER_ENDOWMENT) {
            $data['is_multiple_trusteeship_deed_copy'] = $this->boolean('is_multiple_trusteeship_deed_copy');
        }

        if ($this->exists('instrument_number')) {
            $data['instrument_number'] = $this->input('instrument_number');
        }

        if ($this->input('instrument_type') === 'electronic') {
            $history = $this->resolvedInstrumentHistory();
            if ($history !== null) {
                $data['instrument_history'] = $history;
                $data['type_instrument_history'] = $this->input('type_instrument_history', 'hijri');
            }
        }

        // Sensitive real-estate documents go to the PRIVATE disk and are served only
        // through signed URLs (see App\Support\RealEstateImage) — never world-readable.
        $privateDisk = \App\Support\RealEstateImage::DISK;

        if ($this->hasFile('image_instrument')) {
            $data['image_instrument'] = $this->file('image_instrument')
                ->store('images/real_estates', $privateDisk);
        }

        if ($this->hasFile('image_address')) {
            $data['image_address'] = $this->file('image_address')
                ->store('images/real_estates', $privateDisk);
        }

        if ($this->hasFile('copy_of_the_endowment_registration_certificate')) {
            $data['copy_of_the_endowment_registration_certificate'] = $this->file('copy_of_the_endowment_registration_certificate')
                ->store('real_estates/endowment-registration-certificates', $privateDisk);
        }

        if ($this->hasFile('copy_of_the_trusteeship_deed')) {
            $data['copy_of_the_trusteeship_deed'] = $this->file('copy_of_the_trusteeship_deed')
                ->store('real_estates/trusteeship-deeds', $privateDisk);
        }

        if ($this->hasFile('copy_of_guardians_power_of_attorney_for_agent')) {
            $data['copy_of_guardians_power_of_attorney_for_agent'] = $this->file('copy_of_guardians_power_of_attorney_for_agent')
                ->store('real_estates/guardians-power-of-attorney', $privateDisk);
        }

        return $data;
    }

    public function resolvedInstrumentHistory(): ?string
    {
        $hasDay = $this->filled('instrument_history_day');
        $hasMonth = $this->filled('instrument_history_month');
        $hasYear = $this->filled('instrument_history_year');

        if ($hasDay && $hasMonth && $hasYear) {
            return DateInputNormalizer::combineFromParts(
                $this->input('instrument_history_day'),
                $this->input('instrument_history_month'),
                $this->input('instrument_history_year'),
            );
        }

        if ($this->filled('instrument_history')) {
            $raw = trim((string) $this->input('instrument_history'));
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

    public function resolvedDateFirstRegistration(): ?string
    {
        $hasDay = $this->filled('date_first_registration_day');
        $hasMonth = $this->filled('date_first_registration_month');
        $hasYear = $this->filled('date_first_registration_year');

        if ($hasDay && $hasMonth && $hasYear) {
            return DateInputNormalizer::combineFromParts(
                $this->input('date_first_registration_day'),
                $this->input('date_first_registration_month'),
                $this->input('date_first_registration_year'),
            );
        }

        if ($this->filled('date_first_registration')) {
            $raw = trim((string) $this->input('date_first_registration'));
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
