<?php

use App\Modules\LessorChange\Controllers\Admin\LessorChangeAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('lessor-change')->name('admin.lessor-change.')
    ->controller(LessorChangeAdminController::class)
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('/', 'index')->middleware('permission:lessor_change.view')->name('index');
        Route::get('/{id}', 'show')->whereNumber('id')->middleware('permission:lessor_change.view')->name('show');
        Route::post('/{id}/status', 'updateStatus')->whereNumber('id')->middleware('permission:lessor_change.edit')->name('status');
        Route::post('/{id}/delete', 'destroy')->whereNumber('id')->middleware('permission:lessor_change.delete')->name('delete');
        // دفعة (د) — ب12: السلة.
        Route::delete('/{id}', 'destroy')->whereNumber('id')->middleware('permission:lessor_change.delete')->name('trash');
        Route::get('/trash', 'trash')->middleware('permission:lessor_change.delete')->name('trash.index');
        Route::post('/{id}/restore', 'restore')->whereNumber('id')->middleware('permission:lessor_change.delete')->name('restore');
    });
