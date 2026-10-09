<?php

use App\Modules\Analytics\Controllers\Admin\EmployeeDashboardAnalyticsController;
use App\Modules\Analytics\Controllers\Admin\HomeAdminController;
use App\Modules\Analytics\Controllers\Admin\LocationAnalyticsController;
use App\Modules\Analytics\Controllers\Admin\ReportController;
use App\Modules\Analytics\Controllers\Admin\UserDashboardAnalyticsController;
use Illuminate\Support\Facades\Route;

// Analytics & Dashboard
Route::controller(HomeAdminController::class)
    ->middleware(['auth:sanctum', 'permission:analytics.view'])
    ->group(function () {
    Route::get('/analytics', 'analysis')->name('analytics');
    Route::get('/analytics/all', 'analysis')->name('analytics.all');
    Route::get('/dashboard-analytics', 'analysis')->name('dashboard-analytics');
});

Route::prefix('analytics/locations')->name('analytics.locations.')
    ->controller(LocationAnalyticsController::class)
    ->middleware(['auth:sanctum', 'permission:analytics.view'])
    ->group(function () {
    Route::get('/cities', 'cities')->name('cities');
    Route::get('/', 'index')->name('index');
});

Route::prefix('analytics')->name('analytics.')
    ->controller(UserDashboardAnalyticsController::class)
    ->middleware(['auth:sanctum', 'permission:analytics.view'])
    ->group(function () {
    Route::get('/user-activity-rate', 'userActivityRate')->name('user-activity-rate');
    Route::get('/top-customers/completed-orders', 'topCustomersCompletedOrders')->name('top-customers.completed-orders');
    Route::get('/top-customers/incomplete-orders', 'topCustomersIncompleteOrders')->name('top-customers.incomplete-orders');
    Route::get('/top-customers/orders', 'topCustomersOrders')->name('top-customers.orders');
    Route::get('/top-customers/returns', 'topCustomersReturns')->name('top-customers.returns');
    Route::get('/top-customers/real-estates', 'topCustomersRealEstates')->name('top-customers.real-estates');
    Route::get('/top-customers/units', 'topCustomersUnits')->name('top-customers.units');
});

// Alias: analytics-clients (same handlers, clients-rich response)
Route::prefix('analytics/clients')->name('analytics.clients.')
    ->controller(UserDashboardAnalyticsController::class)
    ->middleware(['auth:sanctum', 'permission:analytics.view'])
    ->group(function () {
    Route::get('/completed-orders', 'topCustomersCompletedOrders')->name('completed-orders');
    Route::get('/incomplete-orders', 'topCustomersIncompleteOrders')->name('incomplete-orders');
    Route::get('/orders', 'topCustomersOrders')->name('orders');
    Route::get('/returns', 'topCustomersReturns')->name('returns');
    Route::get('/real-estates', 'topCustomersRealEstates')->name('real-estates');
    Route::get('/units', 'topCustomersUnits')->name('units');
});

Route::prefix('analytics/employees')->name('analytics.employees.')
    ->controller(EmployeeDashboardAnalyticsController::class)
    ->middleware(['auth:sanctum', 'permission:analytics.view'])
    ->group(function () {
    Route::get('/most-received-orders', 'mostReceivedOrders')->name('most-received-orders');
    Route::get('/most-returns', 'mostReturns')->name('most-returns');
    Route::get('/most-documented-orders', 'mostDocumentedOrders')->name('most-documented-orders');
    Route::get('/count', 'totalCount')->name('count');
    Route::get('/most-unpaid-orders', 'mostUnpaidOrders')->name('most-unpaid-orders');
});

// Reports page (/home/reports on the admin frontend)
Route::prefix('reports')->name('reports.')
    ->controller(ReportController::class)
    ->middleware('auth:sanctum')
    ->group(function () {
        // دفعة (د) — ب21: «نظرة عامة» بستة أرقام.
        Route::get('/overview', 'overview')->middleware('permission:analytics.view')->name('overview');
        Route::get('/orders', 'orders')->middleware('permission:analytics.view')->name('orders');
        Route::get('/sales', 'sales')->middleware('permission:analytics.view')->name('sales');
        Route::get('/profits', 'profits')->middleware('permission:analytics.view')->name('profits');
        Route::get('/profit-settings', 'profitSettingsShow')->middleware('permission:analytics.view')->name('profit-settings.show');
        Route::put('/profit-settings', 'profitSettingsUpdate')->middleware('permission:analytics.edit')->name('profit-settings.update');
        Route::get('/customers', 'customers')->middleware('permission:analytics.view')->name('customers');
        Route::get('/performance', 'performance')->middleware('permission:analytics.view')->name('performance');
        Route::get('/marketing', 'marketing')->middleware('permission:analytics.view')->name('marketing');
        Route::get('/marketing/utm-template', 'marketingUtmTemplate')->middleware('permission:analytics.view')->name('marketing.utm-template');
        Route::post('/marketing/spend', 'importAdSpend')->middleware('permission:analytics.create')->name('marketing.spend');
        Route::post('/marketing/sync', 'syncAdSpend')->middleware('permission:analytics.create')->name('marketing.sync');
    });
