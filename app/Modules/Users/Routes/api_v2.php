<?php

use App\Modules\Users\Controllers\Api\AccountController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'ensure.customer'])->group(function () {
    Route::controller(AccountController::class)->group(function () {
        Route::get('/profile', 'profile');
        Route::post('/profile', 'updateProfile');
        Route::post('/update/password', 'updatePassword');
        Route::post('/fcm', 'updateFCMToken');
        Route::get('/notifications', 'notifications');
        Route::get('/notifications/unread-count', 'unreadCount');
        Route::post('/notifications/read-all', 'markAllNotificationsRead');
        Route::post('/notifications/{id}/read', 'markNotificationRead')->whereNumber('id');
        Route::post('/user/deactivate', 'deactivateUser');
    });
});
