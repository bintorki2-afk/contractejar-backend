<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('real_units', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('real_estates_units_id')->nullable();
            $table->string('unit_area')->nullable();
            $table->enum('unit_usage', ['family_residence', 'individual_residence', 'families', 'residential_commercial', 'collective_housing'])->nullable();
            $table->unsignedBigInteger('unit_type_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('number_of_rooms')->nullable();
            $table->string('Gasmeter')->nullable();
            $table->string('floor_number')->nullable();
            $table->string('Number_parking_spaces')->nullable();
            $table->string('unit_number')->nullable();
            $table->string('electricity_meter_number')->nullable();
            $table->string('water_meter_number')->nullable();
            $table->string('number_of_unit_air_conditioners')->nullable();
            $table->string('The_number_of_the_toilet')->nullable();
            $table->string('The_number_of_halls')->nullable();
            $table->string('The_number_of_kitchens')->nullable();
            $table->integer('Services')->nullable()->default(0);
            $table->integer('is_deleted')->default(0);
            $table->boolean('kitchen_tank')->default(false);
            $table->boolean('furnished')->default(false);
            $table->string('type_furnished')->nullable();
            $table->boolean('electricity_meter')->default(false);
            $table->boolean('water_meter')->default(false);
            $table->unsignedBigInteger('unit_usage_id')->nullable();
            $table->string('tootal_rooms')->nullable();
            $table->string('The_number_of_toilets')->nullable();
            $table->integer('window_ac')->nullable();
            $table->integer('split_ac')->nullable();
            $table->enum('contract_type', ['housing', 'commercial'])->nullable();
            $table->enum('electricity_meter_ownership', ['owner', 'tenant', 'shared'])->nullable();
            $table->enum('water_meter_ownership', ['owner', 'tenant', 'shared'])->nullable();
            $table->foreign('real_estates_units_id', 'real_units_real_estates_units_id_foreign')->references('id')->on('real_estates')->nullOnDelete();
            $table->foreign('unit_type_id', 'real_units_unit_type_id_foreign')->references('id')->on('unit_types')->nullOnDelete();
            $table->foreign('unit_usage_id', 'real_units_unit_usage_id_foreign')->references('id')->on('unit_usages')->nullOnDelete();
            $table->foreign('user_id', 'real_units_user_id_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('real_units');
    }
};
