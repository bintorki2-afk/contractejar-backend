<?php

use App\Modules\Coupons\Controllers\Api\UserCouponController;
use App\Modules\Coupons\Controllers\Api\V2\CouponAvailabilityController as V2CouponAvailabilityController;
use App\Modules\Coupons\Controllers\Api\V2\CouponController as V2CouponController;
use Illuminate\Support\Facades\Route;

Route::get('/coupons/available', [V2CouponAvailabilityController::class, 'available']);

Route::middleware(['auth:sanctum', 'ensure.customer'])->group(function () {
    Route::controller(UserCouponController::class)->group(function () {
        Route::get('/coupons/mine', 'mine');
        Route::post('/coupons/login-notification/ack', 'acknowledge');
    });

    Route::controller(V2CouponController::class)->group(function () {
        Route::post('/Coupon/{uuid}', 'Coupon');
    });
});
