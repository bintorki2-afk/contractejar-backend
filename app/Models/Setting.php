<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use \App\Models\Concerns\FlushesPublicCache;
    use HasFactory;

    protected $fillable = [
        'whatsapp',
        'instagram',
        'twitter',
        'snapchat',
        'facebook',
        'tiktok',
        'linkedIn',
        'whatsapp_contact',
        'whatsapp_contract',
        'housing_tax',
        'commercial_tax',
        'vat_rate',
        'application_fees',
        'open_payment',
        'is_open',
        'working_hours',
        'version',
        'time_to_documentation_contract',
        'text_message_user',
        'text_message_admin',
        'sms_user',
        'sms_owner',
        'sms_employee',
        'electricity_meter_fee_commercial_tenant',
        'electricity_meter_fee_housing_tenant',
        'water_meter_fee_commercial_tenant',
        'water_meter_fee_housing_tenant',
        'cover',
        'banner',
        'moyasar_fee_percent',
        'moyasar_mada_percent',
        'moyasar_credit_percent',
        'moyasar_fixed_fee',
        'monthly_salaries',
        'operating_budget',
        'marketing_budget',
        'meter_transfer_fee',
        'doc_fee_housing_first_year',
        'doc_fee_housing_extra_year',
        'doc_fee_commercial_first_year',
        'doc_fee_commercial_extra_year',
        'document_surcharge_fee',
        'lessor_change_fee',
    ];
}
