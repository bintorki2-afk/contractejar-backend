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

// دفعة (هـ) — 2.2/2.3: الحوالة البنكية والرسوم على الطلب.
Route::prefix('orders')->name('orders.')->controller(\App\Modules\Payments\Controllers\Admin\OrderPaymentController::class)->middleware('auth:sanctum')->group(function () {
    Route::get('/{id}/payment-state', 'state')->whereNumber('id')->middleware('permission:all_requests.view')->name('payment-state');
    Route::get('/{id}/bank-transfer-message', 'bankTransferMessage')->whereNumber('id')->middleware('permission:all_requests.view')->name('bank-transfer-message');
    Route::post('/{id}/payments/bank-transfer', 'bankTransfer')->whereNumber('id')->middleware('permission:payments.record_transfer')->name('payments.bank-transfer');
    Route::get('/{id}/charges', 'index')->whereNumber('id')->middleware('permission:all_requests.view')->name('charges.index');
    Route::post('/{id}/charges', 'store')->whereNumber('id')->middleware('permission:payments.add_fee')->name('charges.store');
    Route::post('/{id}/charges/{cid}/payment-link', 'paymentLink')->whereNumber('id')->whereNumber('cid')->middleware('permission:all_requests.edit')->name('charges.payment-link');
    Route::post('/{id}/charges/{cid}/cancel', 'cancel')->whereNumber('id')->whereNumber('cid')->middleware('permission:all_requests.edit')->name('charges.cancel');
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
    // QA-F C5: كانت مفتوحة بلا توكن (حالة الدفع والمبلغ ورابط الدفع لأي رقم طلب). اللوحة فقط تستخدمها.
    Route::get('/{uuid}', [ContractPaymentController::class, 'paymentUrl'])
        ->middleware(['auth:sanctum', 'permission:all_requests.view', 'throttle:payment-public'])
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
