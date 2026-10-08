<?php

namespace App\Modules\Contracts\Models\Concerns;

use App\Models\ContractPeriod;
use App\Models\ServicesPricing;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Request;

trait HasContractAccessors
{
    public function getContractAttribute()
    {
        $contractsToDelete = $this->where('is_delete', 0)
            ->where(function ($query) {
                $query->where('is_completed', 0)
                    ->orderBy('updated_at', 'desc')
                    ->where('created_at', '<', now()->subDays(7));
            })
            ->get();

        foreach ($contractsToDelete as $contract) {
            $contract->update(['is_delete' => 1]);
        }

        $query = $this->where('is_delete', 0)->where('step', '>=', 5);

        $query->when(Request::filled('uuid'), function ($q) {
            $q->where('uuid', 'like', '%'.Request::get('uuid').'%');
        });

        $query->when(Request::filled('contract_ownership'), function ($q) {
            $q->where('contract_ownership', 'like', '%'.Request::get('contract_ownership').'%');
        });

        $query->when(Request::filled('contract_type'), function ($q) {
            $q->where('contract_type', 'like', '%'.Request::get('contract_type').'%');
        });

        return $query->orderBy('id', 'desc')->paginate(10);
    }

    public function getContractDeleteAttribute()
    {
        $query = $this->where('is_delete', 1);

        if (! empty(Request::get('uuid'))) {
            $query = $query->where('uuid', 'like', '%'.Request::get('uuid').'%');
        }

        if (! empty(Request::get('contract_ownership'))) {
            $query = $query->where('contract_ownership', 'like', '%'.Request::get('contract_ownership').'%');
        }

        if (! empty(Request::get('contract_type'))) {
            $query = $query->where('contract_type', 'like', '%'.Request::get('contract_type').'%');
        }

        return $query->orderBy('contracts.id', 'desc')->paginate(10);
    }

    public function getCreatedAtLabelAttribute()
    {
        return date('Y-m-d H:i A', strtotime($this->created_at));
    }

    public function getUpdateAtLabelAttribute()
    {
        return date('Y-m-d H:i A', strtotime($this->updated_at));
    }

    public function getCopyOfTheoOwnerRecord()
    {
        return getFilePath($this->copy_of_the_owner_record);
    }

    public function getStrongArgumentPhotoPathAttribute()
    {
        return getFilePath($this->strong_argument_photo);
    }

    public function getPhotoOfElectronic()
    {
        return getFilePath($this->photo_of_the_electronic);
    }

    public function getCopyOfTheAuthorizationOrAgencyPathAttribute()
    {
        return getFilePath($this->copy_of_the_authorization_or_agency);
    }

    public function getContractTypeTransAttribute()
    {
        return self::contractTypeLabel((string) $this->contract_type);
    }

    /**
     * API alias for DB column `dob_hijri_of_property_owner_agent`.
     */
    public function getDobOfPropertyOwnerAgentAttribute(mixed $value): mixed
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        return $this->attributes['dob_hijri_of_property_owner_agent'] ?? null;
    }

    public function setDobOfPropertyOwnerAgentAttribute(mixed $value): void
    {
        $this->attributes['dob_hijri_of_property_owner_agent'] = $value;
    }

    public function getInstrumentTypeTransAttribute()
    {
        return self::instrumentTypeLabel((string) $this->instrument_type);
    }

    public function getDraftBeforePaidPathAttribute()
    {
        return isset($this->draft_before_paid) ? getFilePath($this->draft_before_paid) : '';
    }

    public function getDraftAfterPaidPathAttribute()
    {
        return isset($this->draft_before_paid) ? getFilePath($this->draft_after_paid) : '';
    }

    public function getTotalPriceAttribute()
    {
        // Single source: fee + proportional VAT (VAT is 0 while off => shown as "مجانًا").
        $pricing = \App\Support\ContractPricing::for($this);
        $tax_name = $this->contract_type == 'housing' ? 'Residential_contract_tax' : 'Value_added_tax';

        return [
            'details' => [
                'documentation_fee' => $pricing['fee'],
                $tax_name => $pricing['vat'],
                'vat' => $pricing['vat'],
                'vat_rate' => $pricing['vat_rate'],
                'vat_label' => $pricing['vat_label'],
                'meter_fees_total' => $pricing['meter_fees_total'],
                'document_surcharge' => $pricing['document_surcharge'],
            ],
            'fee' => $pricing['fee'],
            'document_surcharge' => $pricing['document_surcharge'],
            'vat' => $pricing['vat'],
            'vat_label' => $pricing['vat_label'],
            'total_price' => $pricing['total'],
        ];
    }

    public function getServicesAttribute()
    {
        return ServicesPricing::where('contract_type', $this->contract_type)->get();
    }

    /**
     * Canonical documentation fee (SINGLE source of truth).
     *
     * Previously this stacked a wrong ContractPeriod lookup + services + application
     * fees + a flat tax, which double-counted the service fee once callers added the
     * services sum again. It now returns the single-source documentation fee.
     */
    public function getPriceContractAttribute()
    {
        return \App\Support\ContractPricing::fee($this);
    }

    public static function getSingle($id)
    {
        return User::find($id);
    }
}
