<?php

use App\Modules\LessorChange\Controllers\Api\V2\LessorChangeController;
use Illuminate\Support\Facades\Route;

Route::get('/lessor-change/info', [LessorChangeController::class, 'info']);

Route::get('/lessor-change/{request}/image/{field}', [LessorChangeController::class, 'image'])
    ->whereNumber('request')
    ->middleware('signed')
    ->name('lessor-change.image');

Route::get('/payment/lessor-change/{uuid}', [LessorChangeController::class, 'pay'])
    ->middleware('throttle:payment-public')
    ->name('v2.payment.lessor-change');

Route::middleware(['auth:sanctum', 'ensure.customer'])->controller(LessorChangeController::class)->group(function () {
    Route::post('/lessor-change', 'store')->middleware('throttle:20,1');
    Route::get('/lessor-change/mine', 'mine');
    Route::get('/lessor-change/{uuid}/invoice', 'invoice')->whereNumber('uuid');
    Route::get('/lessor-change/{uuid}', 'show');
});
