<?php

namespace App\Http\Requests\Api\V2\Contract;

use App\Http\Requests\Api\V2\BaseApiV2Request;
use App\Http\Requests\Api\V2\Concerns\NormalizesSaudiMobileInputs;
use App\Http\Requests\Api\V2\Concerns\ResolvesContractIdInput;
use App\Models\Contract;
use App\Support\DateInputNormalizer;
use App\Support\HijriDobParts;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Stringable;

class Step3Request extends BaseApiV2Request
{
    use NormalizesSaudiMobileInputs;
    use ResolvesContractIdInput;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // التطبيق يرسل القيم المنطقية كنصوص 'true'/'false' في multipart — قاعدة boolean لا تقبلها.
        foreach (['property_owner_is_deceased', 'add_legal_agent_of_owner'] as $boolKey) {
            if ($this->has($boolKey) && is_string($this->input($boolKey))) {
                $normalized = filter_var($this->input($boolKey), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($normalized !== null) {
                    $this->merge([$boolKey => $normalized ? 1 : 0]);
                }
            }
        }
        $this->resolveContractIdInput();
        $this->normalizeSaudiMobileFields([
            'property_owner_mobile',
            'mobile_of_property_owner_agent',
        ]);

        foreach (['name_owner', 'property_owner_iban'] as $optionalField) {
            if ($this->exists($optionalField) && trim((string) $this->input($optionalField)) === '') {
                $this->merge([$optionalField => null]);
            }
        }

        if (! array_key_exists('add_legal_agent_of_owner', $this->all())) {
            $this->merge(['add_legal_agent_of_owner' => false]);
        } else {
            $add = $this->input('add_legal_agent_of_owner');
            if ($add === null || $add === '') {
                $this->merge(['add_legal_agent_of_owner' => false]);
            } elseif (is_string($add)) {
                $v = strtolower(trim($add));
                if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
                    $this->merge(['add_legal_agent_of_owner' => true]);
                } elseif (in_array($v, ['0', 'false', 'no', 'off'], true)) {
                    $this->merge(['add_legal_agent_of_owner' => false]);
                }
            }
        }

        foreach ([
            'property_owner_dob_day',
            'property_owner_dob_month',
            'property_owner_dob_year',
            'dob_of_property_owner_agent_day',
            'dob_of_property_owner_agent_month',
            'dob_of_property_owner_agent_year',
        ] as $key) {
            if ($this->has($key) && is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }

        // Alias keys used by mobile clients / docs (same as Step3RealEstateRequest).
        if (! $this->filled('property_owner_dob_day') && $this->filled('property_owner_dob_day')) {
            $this->merge([
                'property_owner_dob_day' => $this->input('property_owner_dob_day'),
                'property_owner_dob_month' => $this->input('property_owner_dob_month'),
                'property_owner_dob_year' => $this->input('property_owner_dob_year'),
            ]);
        }

        if ($this->filled('property_owner_dob') && ! $this->filled('property_owner_dob_day')) {
            $raw = (string) $this->input('property_owner_dob');
            $parts = preg_split('/[-\/]/', trim($raw));
            if (count($parts) === 3) {
                $this->merge([
                    'property_owner_dob_day' => (int) $parts[0],
                    'property_owner_dob_month' => (int) $parts[1],
                    'property_owner_dob_year' => (int) $parts[2],
                ]);
            }
        }

        if ($this->filled('dob_of_property_owner_agent') && ! $this->filled('dob_of_property_owner_agent_day')) {
            $raw = (string) $this->input('dob_of_property_owner_agent');
            $parts = preg_split('/[-\/]/', trim($raw));
            if (count($parts) === 3) {
                $this->merge([
                    'dob_of_property_owner_agent_day' => (int) $parts[0],
                    'dob_of_property_owner_agent_month' => (int) $parts[1],
                    'dob_of_property_owner_agent_year' => (int) $parts[2],
                ]);
            }
        }

        if ($this->filled('agency_instrument_date_of_property_owner') && ! $this->filled('agency_instrument_date_of_property_owner_day')) {
            $mysql = DateInputNormalizer::toMysqlDate((string) $this->input('agency_instrument_date_of_property_owner'));
            if ($mysql !== null) {
                $p = DateInputNormalizer::splitMysqlDate($mysql);
                $this->merge([
                    'agency_instrument_date_of_property_owner_day' => (int) $p['day'],
                    'agency_instrument_date_of_property_owner_month' => (int) $p['month'],
                    'agency_instrument_date_of_property_owner_year' => (int) $p['year'],
                ]);
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Builds DD-MM-YYYY from request parts after prepareForValidation (handles JSON ints, trimmed strings; ignores stray files).
     */
    public function resolvedPropertyOwnerDobString(): ?string
    {
        $day = $this->normalizedDobPart($this->input('property_owner_dob_day'));
        $month = $this->normalizedDobPart($this->input('property_owner_dob_month'));
        $year = $this->normalizedDobPart($this->input('property_owner_dob_year'));

        if ($day !== null && $month !== null && $year !== null) {
            return HijriDobParts::combine($day, $month, $year);
        }

        // Fallback: combined date only (parts merge did not run or failed).
        foreach (['property_owner_dob'] as $key) {
            $combined = $this->normalizedCombinedDobString($this->input($key));
            if ($combined !== null) {
                return $combined;
            }
        }

        return null;
    }

    private function normalizedCombinedDobString(mixed $raw): ?string
    {
        if ($raw instanceof UploadedFile || $raw === null || $raw === '') {
            return null;
        }
        if ($raw instanceof Stringable) {
            $raw = $raw->__toString();
        }
        if (! is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $parts = preg_split('/[-\/]/', $trimmed);
        if (count($parts) !== 3) {
            return null;
        }

        return HijriDobParts::combine($parts[0], $parts[1], $parts[2]);
    }

    private function normalizedDobPart(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return null;
        }
        if ($value instanceof Stringable) {
            $value = $value->__toString();
        }
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return null;
    }

    public function rules(): array
    {
        if ($this->isLeaseRenewalContract()) {
            return $this->leaseRenewalRules();
        }

        // Representative mode (#30/#31): owner deceased (وكيل الورثة) or waqf (ناظر الوقف).
        // The website sends ONLY the legal representative (agent) block + add_legal_agent_of_owner=1
        // (+ property_owner_is_deceased=1 for the deceased path); the living-owner fields are hidden.
        $ownerRequired = $this->isRepresentativeMode() ? 'nullable' : 'required';

        return [
            'id' => 'required|exists:contracts,id',
            'type_dob_property_owner' => 'nullable|in:hijri,gregorian',
            'type_dob_property_owner_agent' => 'nullable|in:hijri,gregorian',
            'type_agency_instrument_date_of_property_owner' => 'nullable|in:hijri,gregorian',
            'name_real_estate' => 'nullable|string|max:255',
            'name_owner' => 'nullable|string',
            'property_owner_id_num' => $ownerRequired.'|min:10|regex:/^[12]\d{9}$/',
            'property_owner_dob_day' => $ownerRequired,
            'property_owner_dob_month' => $ownerRequired,
            'property_owner_dob_year' => $ownerRequired,
            'property_owner_mobile' => $ownerRequired.'|min:9|regex:/^5[0-9]{8}$/',
            'property_owner_iban' => 'nullable|string|min:22',
            'property_owner_is_deceased' => 'nullable|boolean',
            'add_legal_agent_of_owner' => 'nullable|boolean',
            'id_num_of_property_owner_agent' => 'nullable|required_if:add_legal_agent_of_owner,1|min:10|regex:/^[12]\d{9}$/',
            'dob_of_property_owner_agent_day' => 'nullable|required_if:add_legal_agent_of_owner,1',
            'dob_of_property_owner_agent_month' => 'nullable|required_if:add_legal_agent_of_owner,1',
            'dob_of_property_owner_agent_year' => 'nullable|required_if:add_legal_agent_of_owner,1',
            'mobile_of_property_owner_agent' => 'nullable|required_if:add_legal_agent_of_owner,1|min:9|regex:/^5[0-9]{8}$/',
            'agency_number_in_instrument_of_property_owner' => 'nullable|string|max:255',
            'agency_instrument_date_of_property_owner' => 'nullable|string|max:32',
            'copy_of_the_authorization_or_agency' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ];
    }

    /**
     * True when the owner step is submitted for the legal representative of a deceased owner
     * or of a waqf (the contract's instrument type says so, or the client flags it).
     */
    public function isRepresentativeMode(): bool
    {
        if ($this->boolean('property_owner_is_deceased') && $this->boolean('add_legal_agent_of_owner')) {
            return true;
        }

        $contractId = $this->input('id');
        if (! $contractId) {
            return false;
        }

        $instrumentType = (string) Contract::query()->whereKey($contractId)->value('instrument_type');

        return $this->boolean('add_legal_agent_of_owner')
            && in_array($instrumentType, self::REPRESENTATIVE_INSTRUMENT_TYPES, true);
    }

    /** Instrument types whose owner step is completed by a legal representative, not the owner. */
    public const REPRESENTATIVE_INSTRUMENT_TYPES = [
        'property_ownership_owner_are_deceased',
        'property_ownership_owner_are_deceased_endowment',
        'property_ownership_owner_is_endowment',
    ];

    /**
     * lease_renewal: requested edits are required; other fields optional.
     */
    private function leaseRenewalRules(): array
    {
        return [
            'id' => 'required|exists:contracts,id',
            'type_dob_property_owner' => 'nullable|in:hijri,gregorian',
            'type_dob_property_owner_agent' => 'nullable|in:hijri,gregorian',
            'type_agency_instrument_date_of_property_owner' => 'nullable|in:hijri,gregorian',
            'name_real_estate' => 'nullable|string|max:255',
            'name_owner' => 'nullable|string',
            'property_owner_id_num' => 'nullable|min:10|regex:/^[12]\d{9}$/',
            'property_owner_dob_day' => 'nullable',
            'property_owner_dob_month' => 'nullable',
            'property_owner_dob_year' => 'nullable',
            'property_owner_mobile' => 'nullable|min:9|regex:/^5[0-9]{8}$/',
            'property_owner_iban' => 'nullable|string|min:22',
            'add_legal_agent_of_owner' => 'nullable',
            'id_num_of_property_owner_agent' => 'nullable|required_if:add_legal_agent_of_owner,1|min:10|regex:/^[12]\d{9}$/',
            'dob_of_property_owner_agent_day' => 'nullable|required_if:add_legal_agent_of_owner,1',
            'dob_of_property_owner_agent_month' => 'nullable|required_if:add_legal_agent_of_owner,1',
            'dob_of_property_owner_agent_year' => 'nullable|required_if:add_legal_agent_of_owner,1',
            'mobile_of_property_owner_agent' => 'nullable|required_if:add_legal_agent_of_owner,1|min:9|regex:/^5[0-9]{8}$/',
            'copy_of_the_authorization_or_agency' => 'nullable',
        ];
    }

    private function isLeaseRenewalContract(): bool
    {
        $contractId = $this->input('id');

        return $contractId
            && Contract::query()->whereKey($contractId)->value('instrument_type') === 'lease_renewal';
    }

    public function messages(): array
    {
        return $this->contractV2ArabicMessages([
            'id',
            'type_dob_property_owner',
            'type_dob_property_owner_agent',
            'type_agency_instrument_date_of_property_owner',
            'name_real_estate',
            'name_owner',
            'property_owner_id_num',
            'property_owner_dob_day',
            'property_owner_dob_month',
            'property_owner_dob_year',
            'property_owner_mobile',
            'property_owner_iban',
            'add_legal_agent_of_owner',
            'id_num_of_property_owner_agent',
            'dob_of_property_owner_agent_day',
            'dob_of_property_owner_agent_month',
            'dob_of_property_owner_agent_year',
            'mobile_of_property_owner_agent',
            'agency_number_in_instrument_of_property_owner',
            'agency_instrument_date_of_property_owner_day',
            'agency_instrument_date_of_property_owner_month',
            'agency_instrument_date_of_property_owner_year',
            'copy_of_the_authorization_or_agency',
        ]);
    }
}
