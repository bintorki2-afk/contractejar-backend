<?php

namespace App\Modules\Contracts\Models;

use App\Models\ContractStatus;
use App\Models\Setting;
use App\Modules\Contracts\Models\Concerns\AdminSearchableContract;
use App\Modules\Contracts\Models\Concerns\HasContractAccessors;
use App\Modules\Contracts\Models\Concerns\HasContractRelations;
use App\Modules\Contracts\Models\Concerns\HasContractScopes;
use App\Modules\Contracts\Models\Concerns\HasContractTypes;
use App\Services\Marketing\AttributionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Contract extends Model
{
    use AdminSearchableContract;
    use HasContractAccessors;
    use HasContractRelations;
    use HasContractScopes;
    use HasContractTypes;
    use HasFactory;

    protected $table = 'contracts';

    protected $fillable = [
        'contract_type',
        'user_id',
        'contract_ownership',
        'instrument_type',
        'status',
        'instrument_number',
        'instrument_history',
        'date_first_registration',
        'real_estate_registry_number',
        'number_of_units_in_realestate',
        'property_owner_is_deceased',
        'property_usages_id',
        'property_city_id',
        'property_place_id',
        'property_type_id',
        'neighborhood',
        'building_number',
        'postal_code',
        'extra_figure',
        'address_url',
        'number_of_floors',
        'street',
        'property_owner_id_num',
        'property_owner_mobile',
        'property_owner_iban',
        'add_legal_agent_of_owner',
        'id_num_of_property_owner_agent',
        'dob_gregorian_of_property_owner_agent',
        'dob_hijri_of_property_owner_agent',
        'dob_of_property_owner_agent',
        'mobile_of_property_owner_agent',
        'agency_number_in_instrument_of_property_owner',
        'agency_instrument_date_of_property_owner',
        'agent_iban_of_property_owner',
        'tenant_id_num',
        'tenant_dob_gregorian',
        'tenant_dob',
        'tenant_mobile',
        'name_owner',
        'name_real_estate',
        'add_legal_agent_of_tenant',
        'id_num_of_property_tenant_agent',
        'dob_gregorian_of_property_tenant_agent',
        'dob_of_property_tenant_agent',
        'mobile_of_property_tenant_agent',
        'agency_number_in_instrument_of_property_tenant',
        'agency_instrument_date_of_property_tenant',
        'tenant_entity',
        'tenant_entity_unified_registry_number',
        'tenant_entity_region_id',
        'tenant_entity_city_id',
        'authorization_type',
        'copy_of_the_authorization_or_agency',
        'copy_of_the_owner_record',
        'city_of_the_tenant_legal_agent',
        'region_of_the_tenant_legal_agent',
        'unit_number',
        'unit_type_id',
        'tootal_rooms',
        'floor_number',
        'unit_area',
        'electricity_meter_number',
        'water_meter_number',
        'number_of_unit_air_conditioners',
        'contract_starting_date',
        'contract_term_in_years',
        'annual_rent_amount_for_the_unit',
        'payment_type_id',
        'daily_fine',
        'sub_delay',
        'other_conditions',
        'other_conditions_list',
        'premium_membership_for_free',
        'deposit',
        'Guarantee_amount',
        'contract_period_id',
        'real_id',
        'real_units_id',
        'unit_usage_id',
        'client_account_holder_name',
        'draft_before_paid',
        'draft_after_paid',
        'bank_account_number',
        'The_number_of_the_toilet',
        'The_number_of_halls',
        'number_of_councils',
        'The_number_of_kitchens',
        'Gasmeter',
        'Number_parking_spaces',
        'rating',
        'rating_note',
        'expiry_date',
        'Services',
        'step',
        'is_completed',
        'is_draft',
        'is_delete',
        'is_real',
        'file',
        'is_review',
        'notes',
        'app_or_web',
        'image_instrument',
        'image_instrument_pages',
        'age_of_the_property',
        'number_of_units_per_floor',
        'image_address',
        'latitude',
        'longitude',
        'image_instrument_from_the_front',
        'image_instrument_from_the_back',
        'Image_from_the_agency',
        'copy_power_of_attorney_from_heirs_to_agent',
        'Image_inheritance_certificate',
        'tenant_roles',
        'tenant_role_id',
        'tenant_role_ids',
        'tenant_role_values',
        'text_additional_terms',
        'notes_edits',
        'additional_terms',
        'contract_status_id',
        'type_dob',
        'type_dob_property_owner',
        'type_dob_property_owner_agent',
        'type_tenant_dob',
        'type_dob_tenant_agent',
        'type_contract_starting_date',
        'type_instrument_history',
        'type_date_first_registration',
        'type_agency_instrument_date_of_property_owner',
        'copy_of_the_endowment_registration_certificate',
        'copy_of_the_trusteeship_deed',
        'is_multiple_trusteeship_deed_copy',
        'property_owner_dob',
        'split_ac',
        'window_ac',
        'kitchen_tank',
        'furnished',
        'type_furnished',
        'draft_contract_status_id',
        'electricity_meter_ownership',
        'water_meter_ownership',
        'duration_preset',
        'duration_years',
        'duration_months',
        'total_months',
        'copy_of_guardians_power_of_attorney_for_agent',
        'The_number_of_toilets',
        'deed_addition_method',
        'deed_number',
        'ejar_contract_number',
        'ejar_status_notes',
        'status_attachment',
        'ejar_contract_draft_number',
        'draft_contact_number_mode',
        'draft_contact_number',
    ];

    protected $casts = [
        'tenant_role_ids' => 'array',
        'tenant_role_values' => 'array',
        'image_instrument_pages' => 'array',
        'other_conditions_list' => 'array',
        'kitchen_tank' => 'boolean',
        'furnished' => 'boolean',
        'accept_retrun_contract' => 'boolean',
        'is_draft' => 'boolean',
        'attributed_at' => 'datetime',
        'delay_flags' => 'array',
        'delay_flagged_at' => 'datetime',
    ];

    protected $appends = [
        'created_at_label',
        'strong_argument_photo_path',
        'copy_of_the_authorization_or_agency_path',
        'contract_type_trans',
        'instrument_type_trans',
        'draft_before_paid_path',
        'draft_after_paid_path',
        'total_price',
        'dob_of_property_owner_agent',
    ];

    public static function boot()
    {
        parent::boot();

        self::creating(function ($model) {
            $model->uuid = self::generateUUID();

            if ($model->app_or_web === null || $model->app_or_web === '') {
                $model->app_or_web = 'app';
            }

            if (empty($model->contract_status_id)
                && Schema::hasTable('contract_statuses')
                && ContractStatus::query()->whereKey(ContractStatus::NEW_ID)->exists()) {
                $model->contract_status_id = ContractStatus::NEW_ID;
            }

            app(AttributionService::class)->stampOnCreating($model);
        });

        self::saving(function (Contract $model): void {
            if ($model->isDirty('tenant_role_ids')) {
                $ids = $model->tenant_role_ids;
                $normalized = is_array($ids)
                    ? array_values(array_unique(array_filter(array_map(static fn ($v) => (int) $v, $ids))))
                    : [];
                $model->tenant_role_ids = $normalized !== [] ? $normalized : null;
                $model->tenant_role_id = $normalized[0] ?? null;

                return;
            }

            if ($model->isDirty('tenant_role_id')) {
                $tid = $model->tenant_role_id;
                $model->tenant_role_ids = $tid !== null && $tid !== '' ? [(int) $tid] : null;
            }
        });
    }

    public static function generateUUID()
    {
        do {
            $uuid = random_int(100000, 999999);
        } while (self::uuidInUse((string) $uuid));

        return $uuid;
    }

    /**
     * رقم الطلب (uuid) مشترك كمفتاح للدفعات (payments.contract_uuid) بين العقود
     * وطلبات تغيير المؤجر وروابط دفع الموظف. نفحص كل هذه الجداول حتى لا يتصادم
     * رقم عقد جديد مع رقم طلب آخر فيُعتبر «مدفوعاً» خطأً. (CROSS-4)
     */
    public static function uuidInUse(string $uuid): bool
    {
        if (self::query()->where('uuid', $uuid)->exists()) {
            return true;
        }

        $tables = [
            'lessor_change_requests' => 'uuid',
            'contract_paid_by_employees' => 'contract_uuid',
            'payments' => 'contract_uuid',
        ];

        foreach ($tables as $table => $column) {
            try {
                if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                    continue;
                }

                $exists = \Illuminate\Support\Facades\DB::table($table)
                    ->where($column, $uuid)
                    ->orWhere($column, 'like', $uuid.'-%')
                    ->exists();

                if ($exists) {
                    return true;
                }
            } catch (\Throwable) {
                // جدول غير متاح في بيئة الاختبار المصغّرة — نتجاهله.
            }
        }

        return false;
    }

    protected static bool $documentationOffsetDaysLoaded = false;

    protected static ?int $documentationOffsetDaysValue = null;

    public static function documentationOffsetDays(): ?int
    {
        if (! static::$documentationOffsetDaysLoaded) {
            $raw = Setting::value('time_to_documentation_contract');
            static::$documentationOffsetDaysValue = $raw === null || $raw === '' ? null : (int) $raw;
            static::$documentationOffsetDaysLoaded = true;
        }

        return static::$documentationOffsetDaysValue;
    }

    public function documentationDeadlineAt(): ?\Illuminate\Support\Carbon
    {
        $days = static::documentationOffsetDays();
        if ($days === null || $this->created_at === null) {
            return null;
        }

        return $this->created_at->copy()->addDays($days);
    }
}
