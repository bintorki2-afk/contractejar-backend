<?php

use App\Modules\RealEstate\Controllers\Api\V2\RealEstateControllor as V2RealEstateControllor;
use App\Modules\RealEstate\Controllers\Api\V2\RealEstateDocImageController;
use App\Modules\RealEstate\Controllers\Api\V2\SavedRealEstateController as V2SavedRealEstateController;
use App\Modules\RealEstate\Controllers\Api\V2\UnitEstateController as V2UnitEstateController;
use Illuminate\Support\Facades\Route;

// Sensitive real-estate documents are private: reachable only through a temporary
// signed URL (no auth header needed so <img> tags work, but the signature + expiry
// are enforced).
Route::get('/realstate/{realEstate}/doc-image/{field}', [RealEstateDocImageController::class, 'show'])
    ->middleware('signed')
    ->name('real-estates.doc-image');

Route::middleware(['auth:sanctum', 'ensure.customer'])->group(function () {
    Route::controller(V2SavedRealEstateController::class)->group(function () {
        Route::post('/save/property', 'SavedRealEstate');
    });

    foreach (['realstate', 'realState'] as $realEstatePrefix) {
        Route::prefix($realEstatePrefix)->controller(V2RealEstateControllor::class)->group(function () {
            Route::post('/step1', 'step1');
            Route::post('/step2', 'step2');
            Route::post('/step3', 'step3');
            Route::post('/update/step1', 'updateStep1');
            Route::post('/update/step2', 'updateStep2');
            Route::post('/update/step3', 'updateStep3');
        });
    }

    Route::controller(V2RealEstateControllor::class)->group(function () {
        foreach (['realstate', 'realState'] as $p) {
            Route::get("/{$p}/index", 'index');
            Route::get("/{$p}/show/{id}", 'show');
            Route::get("/{$p}/units/{id}", 'showUnits');
            Route::delete("/{$p}/delete/{id}", 'delete');
            Route::get("/{$p}/all", 'all');
        }
    });

    Route::prefix('unit')->controller(V2UnitEstateController::class)->group(function () {
        Route::post('/create', 'create');
        Route::get('/index/{id}', 'index');
        Route::get('/show/{id}', 'show');
        Route::post('/update/{id}', 'update');
        Route::delete('/delete/{id}', 'delete');
        Route::get('/all/{id}', 'all');
    });
});
