<?php

namespace App\Modules\Contracts\Models\Concerns;

use App\Models\Account;
use App\Models\City;
use App\Models\ContractComment;
use App\Models\ContractPaidByEmployee;
use App\Models\ContractPeriod;
use App\Models\ContractStatus;
use App\Models\ContractStatusHistory;
use App\Models\ContractUnit;
use App\Models\DraftContractStatus;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentType;
use App\Models\ReaEstatType;
use App\Models\ReaEstatUsage;
use App\Models\RealEstate;
use App\Models\ReceivedContract;
use App\Models\RefundableContract;
use App\Models\Region;
use App\Models\TenantRole;
use App\Models\UnitsReal;
use App\Models\UnitType;
use App\Models\UsageUnit;
use App\Models\User;

trait HasContractRelations
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function realEstate()
    {
        // دفعة (و) — D6: الطلب يبقى يعرض عقاره حتى لو نقله العميل للمحذوفات.
        return $this->belongsTo(RealEstate::class, 'real_id')->withTrashed();
    }

    /** Units count: from linked real estate when real_id is set, else contract column. */
    public function numberOfUnitsInRealestate(): mixed
    {
        if ($this->real_id) {
            $this->loadMissing('realEstate');

            return $this->realEstate?->number_of_units_in_realestate ?? $this->number_of_units_in_realestate;
        }

        return $this->number_of_units_in_realestate;
    }

    public function propertyType()
    {
        return $this->belongsTo(ReaEstatType::class, 'property_type_id');
    }

    public function propertyUsages()
    {
        return $this->belongsTo(ReaEstatUsage::class, 'property_usages_id');
    }

    public function propertyCity()
    {
        return $this->belongsTo(City::class, 'property_city_id');
    }

    public function propertyRegion()
    {
        return $this->belongsTo(Region::class, 'property_place_id');
    }

    public function tenantEntityLegalRegion()
    {
        return $this->belongsTo(City::class, 'region_of_the_tenant_legal_agent');
    }

    public function unit()
    {
        return $this->belongsTo(UnitsReal::class, 'real_units_id')->withTrashed();
    }

    /**
     * Units linked to this contract via contract_units (one or more).
     */
    public function units()
    {
        return $this->belongsToMany(
            UnitsReal::class,
            'contract_units',
            'contract_id',
            'real_unit_id'
        )->withPivot(['real_estate_id'])->withTimestamps()->withTrashed();
    }

    public function contractUnits()
    {
        return $this->hasMany(ContractUnit::class, 'contract_id');
    }

    public function tenantEntityLegalCity()
    {
        return $this->belongsTo(City::class, 'city_of_the_tenant_legal_agent');
    }

    public function tenantEntityCity()
    {
        return $this->belongsTo(City::class, 'tenant_entity_city_id');
    }

    public function tenantEntityRegion()
    {
        return $this->belongsTo(Region::class, 'tenant_entity_region_id');
    }

    public function unitType()
    {
        return $this->belongsTo(UnitType::class, 'unit_type_id');
    }

    public function unitUsage()
    {
        return $this->belongsTo(UsageUnit::class, 'unit_usage_id');
    }

    public function contractTermInYears()
    {
        return $this->belongsTo(ContractPeriod::class, 'contract_term_in_years');
    }

    public function paymentType()
    {
        return $this->belongsTo(PaymentType::class, 'payment_type_id');
    }

    public function tenantRole()
    {
        return $this->belongsTo(TenantRole::class, 'tenant_role_id');
    }

    /**
     * Payments linked by contract UUID (see payments.contract_uuid).
     */
    public function contractPayments()
    {
        return $this->hasMany(Payment::class, 'contract_uuid', 'uuid');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function payments()
    {
        return $this->belongsToMany(Payment::class);
    }

    public function receivedContract()
    {
        return $this->hasOne(ReceivedContract::class, 'contract_id');
    }

    // ───────── دفعة (هـ) ─────────

    /** الرسوم (فرق سعر / رسوم إضافية). */
    public function charges()
    {
        return $this->hasMany(\App\Models\ContractCharge::class, 'contract_id');
    }

    /** طلبات المرفق الناقص. */
    public function dataRequests()
    {
        return $this->hasMany(\App\Models\ContractDataRequest::class, 'contract_id');
    }

    /** الدفعات المرتبطة بـ contract_id (تشمل دفعات الرسوم والحوالات). */
    public function paymentRows()
    {
        return $this->hasMany(\App\Models\Payment::class, 'contract_id');
    }

    /** الاسترجاعات (Moyasar/يدوي). */
    public function refunds()
    {
        return $this->hasMany(\App\Models\Refund::class, 'contract_id');
    }

    public function contractStatus()
    {
        return $this->belongsTo(ContractStatus::class, 'contract_status_id');
    }

    public function draftContractStatus()
    {
        return $this->belongsTo(DraftContractStatus::class, 'draft_contract_status_id');
    }

    public function acceptRetrunContractEmployee()
    {
        return $this->belongsTo(Employee::class, 'accept_retrun_contract_employee_id');
    }

    public function refundableContract()
    {
        return $this->hasOne(RefundableContract::class, 'contract_id')->latestOfMany();
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'contract_id');
    }

    public function statusHistories()
    {
        return $this->hasMany(ContractStatusHistory::class, 'contract_id')->orderBy('id');
    }

    public function contractPaidByEmployees()
    {
        return $this->hasMany(ContractPaidByEmployee::class, 'contract_uuid', 'uuid');
    }

    public function comments()
    {
        return $this->hasMany(ContractComment::class, 'contract_id');
    }
}
