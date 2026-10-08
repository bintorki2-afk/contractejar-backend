<?php

use App\Modules\Payments\Controllers\Admin\ContractPaymentController;
use App\Modules\Payments\Controllers\Admin\PaymentController;
use App\Modules\Payments\Controllers\Admin\PaymentMessageController;
use Illuminate\Support\Facades\Route;

// دفعة (د) — ب8: الاسترجاع عبر Moyasar (قبل /payments/{id} حتى لا يلتقطه).
Route::prefix('payments')->name('payments.')->controller(\App\Modules\Payments\Controllers\Admin\PaymentRefundController::class)->middleware('auth:sanctum')->group(function () {
    Route::get('/refunds', 'index')->middleware('permission:payments.view')->name('refunds.index');
    Route::post('/{payment}/refund', 'store')->whereNumber('payment')->middleware('permission:payments.refund')->name('refund');
});

// Payments Management
Route::prefix('payments')->name('payments.')->controller(PaymentController::class)->middleware(['auth:sanctum', 'permission:payments.view'])->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/{id}', 'show')->name('show');
});

// Contract payment gateway (ClickPay) — admin equivalents of routes/api.php payment block (no auth)
Route::prefix('payment-gateway')->name('payment-gateway.')->group(function () {
    Route::post('/status/{uuid}/success', [ContractPaymentController::class, 'updateCartByIPN'])->name('callback');
    Route::post('/status/{uuid}', [ContractPaymentController::class, 'callback'])->name('return');
    Route::get('/status/success/{uuid}', [ContractPaymentController::class, 'success'])->name('status.success');
    Route::get('/status/error/{uuid}', [ContractPaymentController::class, 'error'])->name('status.error');
    Route::get('/{uuid}/payments', [ContractPaymentController::class, 'paymentsByContract'])
        ->middleware(['auth:sanctum', 'permission:payments.view'])
        ->name('payments');
    Route::get('/{uuid}', [ContractPaymentController::class, 'paymentUrl'])
        ->middleware('throttle:payment-public')
        ->name('show');
});

// Payment success / failed messages
Route::prefix('payment-messages')->name('payment-messages.')->controller(PaymentMessageController::class)->middleware('auth:sanctum')->group(function () {
    Route::get('/', 'index')->middleware('permission:payment_messages.view')->name('index');
    Route::post('/', 'store')->middleware('permission:payment_messages.create')->name('store');
    Route::get('/{id}', 'show')->whereNumber('id')->middleware('permission:payment_messages.view')->name('show');
    Route::post('/{id}', 'update')->whereNumber('id')->middleware('permission:payment_messages.edit')->name('update');
    Route::post('/{id}/delete', 'destroy')->whereNumber('id')->middleware('permission:payment_messages.delete')->name('destroy');
});
