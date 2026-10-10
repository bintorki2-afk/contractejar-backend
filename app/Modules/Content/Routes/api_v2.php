<?php

use App\Modules\Content\Controllers\Api\GeneralController;
use App\Modules\Content\Controllers\Api\InstructionImageController;
use App\Modules\Content\Controllers\Api\V2\ContentPageController as V2ContentPageController;
use Illuminate\Support\Facades\Route;

Route::controller(GeneralController::class)->group(function () {
    Route::get('/instrument-types', 'instrumentTypes');
    Route::get('/contract-types', 'contractTypes');
    Route::get('/terms-and-conditions', 'termsAndConditions');
    Route::get('/privacy', 'privacy');
    Route::get('/common-questions', 'commonQuestions');
    Route::get('/popup-contracts', 'popupContracts');
    Route::get('/payment-content', 'paymentContent');
    Route::get('/payment-messages', 'paymentContent');
    Route::get('/settings', 'settings');
    Route::get('/cover', 'cover');
});

Route::prefix('instruction-images')->controller(InstructionImageController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/{key}', 'show')->where('key', '[a-z0-9\-]+');
});

Route::prefix('content-pages')->controller(V2ContentPageController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/{pageKey}', 'show')->where('pageKey', 'home|about|faq|faqs|blogs|services');
});

// دفعة (و) — D7: التقييمات العامة (ملخص + القائمة الظاهرة).
Route::get('/reviews', [\App\Modules\Content\Controllers\Api\V2\ReviewController::class, 'index'])->middleware('throttle:120,1');
