<?php

use App\Modules\RealEstate\Controllers\Admin\RealEstateController;
use App\Modules\RealEstate\Controllers\Admin\UnitRealController;
use Illuminate\Support\Facades\Route;

// دفعة (و) — D6: سلة محذوفات العقارات والوحدات (قبل مسار /{id}).
Route::prefix('real-estates')->name('real-estates.')->controller(RealEstateController::class)->middleware(['auth:sanctum', 'permission:real_estates.delete'])->group(function () {
    Route::get('/trash', 'trash')->name('trash');
    Route::post('/{id}/restore', 'restore')->whereNumber('id')->name('restore');
    Route::post('/units/{id}/restore', 'restoreUnit')->whereNumber('id')->name('units.restore');
});

// Real Estate Management
Route::prefix('real-estates')->name('real-estates.')->controller(RealEstateController::class)->middleware(['auth:sanctum', 'permission:real_estates.view'])->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/{id}', 'show')->name('show');
});

// Unit Real Estate Management
Route::prefix('unit-real-estates')->name('unit-real-estates.')->controller(UnitRealController::class)->middleware('auth:sanctum')->group(function () {
    Route::get('/', 'index')->middleware('permission:property_reference.view')->name('index');
    Route::post('/', 'store')->middleware('permission:property_reference.create')->name('store');
    Route::post('/{id}', 'update')->middleware('permission:property_reference.edit')->name('update');
    Route::post('/{id}/delete', 'destroy')->middleware('permission:property_reference.delete')->name('destroy');
});
