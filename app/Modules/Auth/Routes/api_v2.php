<?php

use App\Modules\Auth\Controllers\Api\AuthController;
use App\Modules\Auth\Controllers\Api\V2\WebAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->controller(AuthController::class)->group(function () {
    Route::post('/login', 'login')->middleware('throttle:login');
    Route::post('/signup', 'signup')->middleware('throttle:otp-send');
    Route::post('/verification', 'verification')->middleware('throttle:otp-verify');
    Route::post('/resend', 'resend')->middleware('throttle:otp-send');
    Route::post('/forgot-password', 'forgotPassword')->middleware('throttle:otp-send');
    Route::post('/reset-password-code', 'resetPasswordCode')->middleware('throttle:otp-verify');
    Route::post('/reset-password', 'resetPassword')->middleware('throttle:otp-verify');
});

Route::middleware(['auth:sanctum', 'ensure.customer'])->group(function () {
    Route::controller(AuthController::class)->group(function () {
        Route::post('/auth/logout', 'logout');
    });
});

/*
|--------------------------------------------------------------------------
| Website (SPA) auth — email + password only, no SMS/OTP. Independent of the
| mobile app's phone flow above; shares only the users table + Sanctum.
|--------------------------------------------------------------------------
*/
Route::prefix('auth/web')->controller(WebAuthController::class)->group(function () {
    Route::post('/register', 'register')->middleware('throttle:6,1');
    Route::post('/login', 'login')->middleware('throttle:login');
    Route::post('/forgot-password', 'forgotPassword')->middleware('throttle:6,1');
    Route::post('/reset-password', 'resetPassword')->middleware('throttle:6,1');
    Route::post('/resend-verification', 'resendVerification')->middleware('throttle:6,1');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/web/logout', [WebAuthController::class, 'logout']);
    Route::get('/auth/web/me', [WebAuthController::class, 'me']);
});

// Social sign-in (Google / Apple) via Socialite. Redirect-based (full page),
// returns the SPA a token in the callback's URL fragment.
Route::get('/auth/web/{provider}/redirect', [WebAuthController::class, 'socialRedirect'])
    ->whereIn('provider', ['google', 'apple'])
    ->middleware('throttle:30,1');
Route::get('/auth/web/{provider}/callback', [WebAuthController::class, 'socialCallback'])
    ->whereIn('provider', ['google', 'apple'])
    ->middleware('throttle:30,1');

// Target of the signed link in the verification email. Named so Laravel's
// default VerifyEmail notification routes to it; it verifies then redirects
// back to the website.
Route::get('/auth/web/verify-email/{id}/{hash}', [WebAuthController::class, 'verifyEmail'])
    ->middleware('signed')
    ->name('verification.verify');
