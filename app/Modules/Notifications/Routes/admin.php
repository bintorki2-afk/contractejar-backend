<?php

use App\Modules\Notifications\Controllers\Admin\NotificationController;
use App\Modules\Notifications\Controllers\Admin\SmsController;
use Illuminate\Support\Facades\Route;

// Firebase notifications
Route::prefix('notifications')->name('notifications.')->controller(NotificationController::class)
    ->middleware(['auth:sanctum', 'permission:notifications.create'])
    ->group(function () {
        Route::post('/send', 'send')->name('send');
        Route::post('/user', 'sendToUser')->name('user');
        Route::post('/custom-user', 'sendToCustomUser')->name('custom-user');
        Route::post('/employee', 'sendToEmployee')->name('employee');
        Route::post('/custom-employee', 'sendToCustomEmployee')->name('custom-employee');
        Route::post('/all-users', 'sendToAllUsers')->name('all-users');
        Route::post('/all-employees', 'sendToAllEmployees')->name('all-employees');
        // دفعة (د) — ب10: معاينة الإرسال الجماعي (عدد المستلمين للشريحة).
        Route::post('/broadcast/preview', 'broadcastPreview')->name('broadcast.preview');
    });

// دفعة (هـ): إشعارات اللوحة للموظف الحالي.
Route::prefix('employee-notifications')->name('employee-notifications.')
    ->controller(\App\Modules\Notifications\Controllers\Admin\EmployeeNotificationController::class)
    ->middleware(['auth:sanctum', 'permission:all_requests.view'])
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/read-all', 'readAll')->name('read-all');
        Route::post('/{id}/read', 'read')->whereNumber('id')->name('read');
    });

// سجل إرسال إشعارات العملاء (ف8) — المجدولة والفورية واليدوية
Route::get('/notification-dispatches', [NotificationController::class, 'dispatches'])
    ->middleware(['auth:sanctum', 'permission:notifications.view'])
    ->name('notifications.dispatches');

// Manual SMS send (Taqnyat) — employee token
Route::prefix('sms')->name('sms.')->controller(SmsController::class)->middleware(['auth:sanctum', 'permission:sms.create'])->group(function () {
    Route::post('/message', 'sendMessage')->name('message');
    Route::post('/send', 'send')->name('send');
});
