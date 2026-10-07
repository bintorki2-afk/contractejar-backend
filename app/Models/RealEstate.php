<?php

namespace App\Models;

use App\Models\Concerns\MapsRealEstateOwnerAttributes;
use App\Models\City;
use App\Models\Contract;
use App\Models\ReaEstatType;
use App\Models\ReaEstatUsage;
use App\Models\Region;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RealEstate extends Model
{
    use MapsRealEstateOwnerAttributes;
    /** صك ملكية ومالك العقار وقف — requires deed + endowment registration + trusteeship deed uploads */
    public const INSTRUMENT_TYPE_OWNER_ENDOWMENT = 'property_ownership_owner_is_endowment';

    public const INSTRUMENT_TYPES = [
        'electronic',
        'old_handwritten',
        'strong_argument',
        'electronic_tax_register',
        'property_ownership_owner_are_deceased_endowment',
        self::INSTRUMENT_TYPE_OWNER_ENDOWMENT,
        'sale_agreement',
        'electronic_deed_from_the_ministry_of_justice',
        'economic_cities_authority_suspended',
        'sublease_agreement',
        'lease_renewal',
        'property_ownership_owner_are_suspended',
        'property_ownership_owner_are_deceased',
    ];

    public static function instrumentTypes(): array
    {
        return self::INSTRUMENT_TYPES;
    }

    protected $casts = [
        'property_type_id' => 'integer',
        'property_usages_id' => 'integer',
        'is_multiple_trusteeship_deed_copy' => 'boolean',
        'step' => 'integer',
    ];

    /**
     * Normalize web (0/1) and API (owner|tenant) payloads to stored enum strings.
     */
    public function setContractOwnershipAttribute(mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['contract_ownership'] = null;

            return;
        }
        if ($value === 'owner' || $value === 'tenant') {
            $this->attributes['contract_ownership'] = $value;

            return;
        }
        $this->attributes['contract_ownership'] = in_array($value, [1, '1', true], true) ? 'owner' : 'tenant';
    }
    use HasFactory;
    protected $fillable = [
        'dob_hijri',
        'national_num',
        'mobile',
        'iban_bank',
        'name_owner','property_owner_id_num', 'property_owner_dob_hijri', 'property_owner_mobile', 'property_owner_iban', 'name_real_estate', 
        'number_of_units_in_realestate', 'property_type_id', 'property_usages_id', 'property_place_id', 'neighborhood','user_id', 'source_contract_id',
        'contract_type','instrument_type','id_num_of_property_owner_agent','id_num_of_property_owner_agent ',
        'property_city_id', 'street', 'number_of_floors','building_number',
        'postal_code','extra_figure','real_estate_registry_number'
        ,'date_first_registration','contract_ownership','add_legal_agent_of_owner','dob_of_property_owner_agent',
        'property_owner_is_deceased','instrument_number','instrument_history', 'type_real_estate_other',
        'mobile_of_property_owner_agent','agency_number_in_instrument_of_property_owner','agency_instrument_date_of_property_owner',
        'type_dob_property_owner', 'type_dob_property_owner_agent',
        'type_instrument_history', 'type_date_first_registration', 'type_agency_instrument_date_of_property_owner',
        'copy_of_the_authorization_or_agency',
        'copy_of_the_endowment_registration_certificate',
        'copy_of_the_trusteeship_deed',
        'copy_of_guardians_power_of_attorney_for_agent',
        'is_multiple_trusteeship_deed_copy',
        'image_instrument', 'age_of_the_property', 'number_of_units_per_floor', 'image_address', 'address_url', 'latitude', 'longitude',
        'electricity_meter_ownership', 'water_meter_ownership',
        'step',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function units()
    {
      return $this->hasMany(UnitsReal::class,'real_estates_units_id');        
    }

    public function hasResolvableUnitsCount(?int $realUnitsId = null): bool
    {
        if ($this->number_of_units_in_realestate !== null && $this->number_of_units_in_realestate !== '') {
            return true;
        }

        if ($realUnitsId && $this->units()->whereKey($realUnitsId)->exists()) {
            return true;
        }

        return $this->units()->exists();
    }

    public function syncNumberOfUnitsInRealestate(?int $realUnitsId = null): void
    {
        if ($this->number_of_units_in_realestate !== null && $this->number_of_units_in_realestate !== '') {
            return;
        }

        $count = $this->units()->count();
        if ($count > 0) {
            $this->update(['number_of_units_in_realestate' => (string) $count]);

            return;
        }

        if ($realUnitsId && $this->units()->whereKey($realUnitsId)->exists()) {
            $this->update(['number_of_units_in_realestate' => '1']);
        }
    }


    public function propertyType()
    {
        return $this->belongsTo(ReaEstatType::class, 'property_type_id');
    }

    public function propertyUsages()
    {
        return $this->belongsTo(ReaEstatUsage::class, 'property_usages_id');
    }


    public function tenantEntityCity()
    {
        return $this->belongsTo(City::class, 'property_city_id');
    }

    public function tenantEntityRegion()
    {
        return $this->belongsTo(Region::class, 'property_place_id');
    }

   
    public function contracts()
    {
        return $this->hasMany(Contract::class, 'real_id');
    }
    
 

 
    
 

  

   


}
