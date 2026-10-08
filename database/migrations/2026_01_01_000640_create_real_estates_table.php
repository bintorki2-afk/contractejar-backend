<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('real_estates', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->boolean('add_legal_agent_of_owner')->nullable()->default(false);
            $table->string('id_num_of_property_owner_agent')->nullable();
            $table->string('dob_of_property_owner_agent')->nullable();
            $table->string('mobile_of_property_owner_agent')->nullable();
            $table->string('agency_number_in_instrument_of_property_owner')->nullable();
            $table->date('agency_instrument_date_of_property_owner')->nullable();
            $table->boolean('property_owner_is_deceased')->nullable();
            $table->string('unit_number')->nullable();
            $table->enum('instrument_type', ['electronic', 'old_handwritten', 'strong_argument', 'electronic_tax_register', 'property_ownership_owner_are_deceased_endowment', 'property_ownership_owner_is_endowment', 'sale_agreement', 'electronic_deed_from_the_ministry_of_justice', 'economic_cities_authority_suspended', 'sublease_agreement', 'lease_renewal', 'property_ownership_owner_are_suspended', 'property_ownership_owner_are_deceased'])->nullable();
            $table->enum('contract_type', ['commercial', 'housing'])->nullable();
            $table->string('date_first_registration')->nullable();
            $table->string('real_estate_registry_number')->nullable();
            $table->string('dob_hijri')->nullable();
            $table->string('instrument_number')->nullable();
            $table->string('instrument_history')->nullable();
            $table->string('name_owner')->nullable();
            $table->string('national_num')->nullable();
            $table->string('number_of_units_in_realestate')->nullable();
            $table->string('DOB')->nullable();
            $table->string('mobile')->nullable();
            $table->string('iban_bank')->nullable();
            $table->string('name_real_estate')->nullable();
            $table->string('number_of_floors')->nullable();
            $table->unsignedBigInteger('property_type_id')->nullable();
            $table->unsignedBigInteger('property_usages_id')->nullable();
            $table->string('type_real_estate_other')->nullable();
            $table->unsignedBigInteger('property_city_id')->nullable();
            $table->unsignedBigInteger('property_place_id')->nullable();
            $table->string('street')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('building_number')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('extra_figure')->nullable();
            $table->string('address_url')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('step')->default(0);
            $table->integer('is_deleted')->default(0);
            $table->string('image_instrument')->nullable();
            $table->integer('age_of_the_property')->nullable();
            $table->string('number_of_units_per_floor')->nullable();
            $table->string('image_address')->nullable();
            $table->decimal('latitude', 11, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('copy_of_the_authorization_or_agency')->nullable();
            $table->enum('type_dob_property_owner', ['hijri', 'gregorian'])->default('hijri');
            $table->enum('type_dob_property_owner_agent', ['hijri', 'gregorian'])->nullable();
            $table->enum('type_instrument_history', ['hijri', 'gregorian'])->nullable();
            $table->enum('type_date_first_registration', ['hijri', 'gregorian'])->nullable();
            $table->enum('type_agency_instrument_date_of_property_owner', ['hijri', 'gregorian'])->nullable();
            $table->enum('contract_ownership', ['owner', 'tenant'])->nullable();
            $table->string('copy_of_the_endowment_registration_certificate')->nullable();
            $table->string('copy_of_the_trusteeship_deed')->nullable();
            $table->boolean('is_multiple_trusteeship_deed_copy')->default(false);
            $table->string('copy_of_guardians_power_of_attorney_for_agent')->nullable();
            $table->string('property_owner_id_num')->nullable();
            $table->string('property_owner_dob_hijri')->nullable();
            $table->string('property_owner_mobile')->nullable();
            $table->string('property_owner_iban')->nullable();
            $table->enum('electricity_meter_ownership', ['owner', 'tenant', 'shared'])->nullable();
            $table->enum('water_meter_ownership', ['owner', 'tenant', 'shared'])->nullable();
            $table->foreign('property_city_id', 'real_estates_property_city_id_foreign')->references('id')->on('cities')->nullOnDelete();
            $table->foreign('property_place_id', 'real_estates_property_place_id_foreign')->references('id')->on('regions')->nullOnDelete();
            $table->foreign('property_type_id', 'real_estates_property_type_id_foreign')->references('id')->on('rea_estat_types')->nullOnDelete();
            $table->foreign('property_usages_id', 'real_estates_property_usages_id_foreign')->references('id')->on('rea_estat_usages')->nullOnDelete();
            $table->foreign('user_id', 'real_estates_user_id_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('real_estates');
    }
};
