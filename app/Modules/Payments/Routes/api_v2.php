<?php

use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\CheckApi;
use App\Modules\Payments\Controllers\Api\PaymentController;
use App\Modules\Payments\Controllers\Api\V2\InvoiceController as V2InvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('invoices')->controller(V2InvoiceController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/number/{invoiceNumber}', 'showByNumber')->where('invoiceNumber', 'INV-[A-Za-z0-9\-]+');
        Route::get('/{contractId}', 'show')->whereNumber('contractId');
    });

    Route::get('/contracts/{contractId}/invoice', [V2InvoiceController::class, 'show'])
        ->whereNumber('contractId');
});

Route::post('/status/{uuid}/success', [PaymentController::class, 'updateCartByIPN'])
    ->withoutMiddleware([CheckApi::class, ApiLocalization::class, 'auth:sanctum'])
    ->name('v2.callback');

Route::post('/status/{uuid}', [PaymentController::class, 'Callback'])
    ->withoutMiddleware([CheckApi::class, ApiLocalization::class, 'auth:sanctum'])
    ->name('v2.return');

Route::get('/status/result/{uuid}', [PaymentController::class, 'result'])
    ->withoutMiddleware([CheckApi::class, ApiLocalization::class, 'auth:sanctum'])
    ->middleware('throttle:payment-public')
    ->name('v2.status.result');

Route::get('/status/success/{uuid}', [PaymentController::class, 'success'])
    ->withoutMiddleware([CheckApi::class, ApiLocalization::class, 'auth:sanctum'])
    ->middleware('throttle:payment-public')
    ->name('v2.status.success');

Route::get('/status/error/{uuid}', [PaymentController::class, 'error'])
    ->withoutMiddleware([CheckApi::class, ApiLocalization::class, 'auth:sanctum'])
    ->middleware('throttle:payment-public')
    ->name('v2.status.error');

Route::get('/payment/{uuid}', [PaymentController::class, 'index'])
    ->withoutMiddleware([CheckApi::class, ApiLocalization::class, 'auth:sanctum'])
    ->middleware('throttle:payment-public')
    ->name('v2.payment.show');

Route::get('/payment/result/{uuid}', [PaymentController::class, 'paymentResult'])
    ->withoutMiddleware([CheckApi::class, ApiLocalization::class, 'auth:sanctum'])
    ->middleware('throttle:payment-public')
    ->name('v2.payment.result');

Route::get('/payment/apple-pay/{uuid}', [PaymentController::class, 'applePayConfig'])
    ->withoutMiddleware([CheckApi::class, ApiLocalization::class, 'auth:sanctum'])
    ->middleware('throttle:payment-public')
    ->name('v2.payment.apple-pay');

Route::get('/payment/sync/{uuid}', [PaymentController::class, 'syncFromGateway'])
    ->withoutMiddleware([CheckApi::class, ApiLocalization::class, 'auth:sanctum'])
    ->middleware('throttle:payment-public')
    ->name('v2.payment.sync');
