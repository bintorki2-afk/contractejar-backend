<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdateContractRequest extends FormRequest
{
    /** Not updatable via this endpoint. */
    private const EXCLUDED_COLUMNS = [
        'id',
        'uuid',
        'user_id',
        'is_delete',
        'created_at',
        'updated_at',
        'accept_retrun_contract',
        'accept_retrun_contract_employee_id',
        // Derived from a successful payment (MoyasarPaymentService); never editable by hand.
        'is_completed',
    ];

    /** Enum columns (DB CHECK / MySQL enum): reject unknown values with 422 instead of a 500. */
    public const ENUM_RULES = [
        'contract_type' => 'in:housing,commercial',
        'app_or_web' => 'in:app,web',
        'type_dob_property_owner' => 'in:hijri,gregorian',
        'type_dob_property_owner_agent' => 'in:hijri,gregorian',
        'tenant_entity' => 'in:person,institution',
        'type_tenant_dob' => 'in:hijri,gregorian',
        'authorization_type' => 'in:owner_and_representative_of_record,agent_for_the_tenant,agent_or_authorized_by_registry_owner',
        'type_dob_tenant_agent' => 'in:hijri,gregorian',
        'address_method' => 'in:photo,link,manual',
        'electricity_meter_ownership' => 'in:owner,tenant',
        'water_meter_ownership' => 'in:owner,tenant',
        'type_contract_starting_date' => 'in:hijri,gregorian',
        'type_instrument_history' => 'in:hijri,gregorian',
        'type_date_first_registration' => 'in:hijri,gregorian',
        'type_agency_instrument_date_of_property_owner' => 'in:hijri,gregorian',
        'duration_preset' => 'in:3_months,6_months,1_year,2_years,other',
    ];

    /** Money / count columns: numeric and never negative. */
    public const NON_NEGATIVE_NUMERIC = [
        'annual_rent_amount_for_the_unit', 'Guarantee_amount', 'deposit', 'daily_fine',
        'duration_years', 'duration_months', 'total_months', 'step', 'age_of_the_property',
    ];

    /**
     * Stored file paths (served through storage / the signed deed route): relative, no traversal.
     */
    public const PATH_COLUMNS = [
        'image_instrument', 'image_instrument_from_the_front', 'image_instrument_from_the_back',
        'image_address', 'copy_of_the_endowment_registration_certificate', 'copy_of_the_trusteeship_deed',
        'Image_inheritance_certificate', 'copy_power_of_attorney_from_heirs_to_agent',
        'copy_of_guardians_power_of_attorney_for_agent', 'Image_from_the_agency',
        'copy_of_the_authorization_or_agency', 'copy_of_the_owner_record', 'file',
        'draft_before_paid', 'draft_after_paid',
    ];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** Mobile columns normalized to the canonical 05XXXXXXXX before validation. */
    public const MOBILE_COLUMNS = [
        'property_owner_mobile',
        'tenant_mobile',
        'mobile_of_property_owner_agent',
        'mobile_of_property_tenant_agent',
    ];

    protected function prepareForValidation(): void
    {
        foreach (self::EXCLUDED_COLUMNS as $key) {
            $this->request->remove($key);
        }

        $this->merge(self::normalizeMobiles($this->all()));
    }

    /**
     * The public v2 wizard stores mobiles as national 9 digits (5XXXXXXXX) while the
     * dashboard rule is 05XXXXXXXX; accept 5…, 05…, 966…, 00966…, +966… and canonicalize
     * so an unchanged value re-sent by the dashboard is never rejected. Anything that does
     * not normalize to a Saudi mobile is left untouched (and fails the regex → 422).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> only the mobile keys that were changed
     */
    public static function normalizeMobiles(array $input): array
    {
        $changed = [];

        foreach (self::MOBILE_COLUMNS as $column) {
            if (! array_key_exists($column, $input) || ! is_scalar($input[$column])) {
                continue;
            }

            $raw = trim((string) $input[$column]);
            if ($raw === '') {
                continue;
            }

            $national = \App\Shared\Helpers\SaudiMobile::toNational($raw);
            if ($national !== null && preg_match('/^5\d{8}$/', $national) === 1 && $raw !== '0'.$national) {
                $changed[$column] = '0'.$national;
            }
        }

        return $changed;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::rulesForKeys($this->presentUpdatableKeys());
    }

    /**
     * Server-side format rules for Saudi identifiers, enforced on any of these
     * columns that are present in the update payload.
     *
     * - Saudi national id / iqama: ^[12]\d{9}$ — owner may also be an establishment (700…): ^[127]\d{9}$
     * - Saudi mobile:              ^05\d{8}$
     * - Commercial register (CR):  ^7\d{9}$
     *
     * @var array<string, string>
     */
    public const FORMAT_RULES = [
        // National id / iqama
        'property_owner_id_num' => 'regex:/^[127]\d{9}$/',
        'tenant_id_num' => 'regex:/^[12]\d{9}$/',
        'id_num_of_property_owner_agent' => 'regex:/^[12]\d{9}$/',
        'id_num_of_property_tenant_agent' => 'regex:/^[12]\d{9}$/',
        // Saudi mobile
        'property_owner_mobile' => 'regex:/^05\d{8}$/',
        'tenant_mobile' => 'regex:/^05\d{8}$/',
        'mobile_of_property_owner_agent' => 'regex:/^05\d{8}$/',
        'mobile_of_property_tenant_agent' => 'regex:/^05\d{8}$/',
        // Commercial register / unified registry number
        'tenant_entity_unified_registry_number' => 'regex:/^7\d{9}$/',
        'real_estate_registry_number' => 'regex:/^7\d{9}$/',
    ];

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function rulesForKeys(array $keys): array
    {
        $rules = [];

        foreach ($keys as $column) {
            if ($column === 'tenant_role_ids') {
                $rules['tenant_role_ids'] = ['nullable', 'array'];
                $rules['tenant_role_ids.*'] = ['nullable'];

                continue;
            }

            if (isset(self::FORMAT_RULES[$column])) {
                $rules[$column] = ['nullable', self::FORMAT_RULES[$column]];

                continue;
            }

            if (isset(self::ENUM_RULES[$column])) {
                $rules[$column] = ['nullable', self::ENUM_RULES[$column]];

                continue;
            }

            if (in_array($column, self::NON_NEGATIVE_NUMERIC, true)) {
                $rules[$column] = ['nullable', 'numeric', 'min:0'];

                continue;
            }

            if (in_array($column, self::PATH_COLUMNS, true)) {
                // relative path, no "..", no scheme/absolute prefix
                $rules[$column] = ['nullable', 'string', 'max:2048', 'regex:#^(?!/)(?!.*\.\.)(?![a-z]+:)[^\x00]+$#i'];

                continue;
            }

            $rules[$column] = ['nullable'];
        }

        return $rules;
    }

    /**
     * Keys sent by client that map to contracts table columns.
     *
     * @return list<string>
     */
    public function presentUpdatableKeys(): array
    {
        $allowed = array_flip(self::updatableColumns());

        return collect(array_keys($this->all()))
            ->filter(fn (string $key) => ! in_array($key, self::EXCLUDED_COLUMNS, true)
                && ! str_contains($key, '.')
                && isset($allowed[$key]))
            ->values()
            ->all();
    }

    /**
     * Build payload from raw input (form-data or JSON), then validate.
     *
     * @return array<string, mixed>
     */
    public function updatePayload(): array
    {
        $keys = $this->presentUpdatableKeys();

        if ($keys === []) {
            throw ValidationException::withMessages([
                'payload' => [trans('api.contract_update_requires_field')],
            ]);
        }

        $payload = [];
        foreach ($keys as $key) {
            $payload[$key] = $this->input($key);
        }

        $payload = array_merge($payload, self::normalizeMobiles($payload));

        ValidatorFacade::make($payload, self::rulesForKeys($keys))->validate();

        return $payload;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->presentUpdatableKeys() === []) {
                $validator->errors()->add(
                    'payload',
                    trans('api.contract_update_requires_field')
                );
            }
        });
    }

    /**
     * @return list<string>
     */
    public static function updatableColumns(): array
    {
        if (! Schema::hasTable('contracts')) {
            return [];
        }

        return array_values(array_diff(
            Schema::getColumnListing('contracts'),
            self::EXCLUDED_COLUMNS
        ));
    }
}
