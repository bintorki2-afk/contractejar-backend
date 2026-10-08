<?php

use App\Modules\Settings\Controllers\Api\V2\AppStatusController as V2AppStatusController;
use App\Modules\Settings\Controllers\Api\V2\MeterFeeSettingController as V2MeterFeeSettingController;
use App\Modules\Settings\Controllers\Api\V2\PricingController as V2PricingController;
use App\Modules\Settings\Controllers\Api\V2\SettingContractController as V2SettingContractController;
use App\Modules\Settings\Controllers\Api\V2\SmsSettingController as V2SmsSettingController;
use Illuminate\Support\Facades\Route;

Route::get('/app-status', [V2AppStatusController::class, 'show']);
Route::get('/app/version', [V2AppStatusController::class, 'version']);
Route::get('/website-status', [V2AppStatusController::class, 'website']);

Route::prefix('setting-contracts')->controller(V2SettingContractController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/{id}', 'show')->whereNumber('id');
});

Route::prefix('instrument-type-settings')->controller(V2SettingContractController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/{id}', 'show')->whereNumber('id');
});

Route::get('/sms-settings', [V2SmsSettingController::class, 'show']);
Route::get('/meter-fee-settings', [V2MeterFeeSettingController::class, 'show']);
Route::get('/pricing', [V2PricingController::class, 'show']);
