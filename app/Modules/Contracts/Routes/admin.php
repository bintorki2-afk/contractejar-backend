<?php

use App\Modules\Contracts\Controllers\Admin\ContractCommentController;
use App\Modules\Contracts\Controllers\Admin\ContractPaidByEmployeeController;
use App\Modules\Contracts\Controllers\Admin\ContractStatusController;
use App\Modules\Contracts\Controllers\Admin\ContractUnitController;
use App\Modules\Contracts\Controllers\Admin\ContractWhatsAppController;
use App\Modules\Contracts\Controllers\Admin\DraftContractStatusController;
use App\Modules\Contracts\Controllers\Admin\FilterContract;
use App\Modules\Contracts\Controllers\Admin\OrderController;
use App\Modules\Contracts\Controllers\Admin\PopupContractController;
use App\Modules\Contracts\Controllers\Admin\ReceivedContractController;
use App\Modules\Contracts\Controllers\Admin\RefundableContractController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('refundable-contracts')->name('refundable-contracts.')->controller(RefundableContractController::class)->group(function () {
        Route::get('/', 'index')->middleware('permission:returned_request.view')->name('index');
        Route::post('/', 'store')->middleware('permission:returned_request.create')->name('store');
        Route::get('/{uuid}', 'show')->middleware('permission:returned_request.view')->name('show');
        Route::match(['post', 'put', 'patch'], '/{uuid}', 'update')->middleware('permission:returned_request.edit')->name('update');
    });

    Route::prefix('analytics/refunds')->name('analytics.refunds.')
        ->controller(RefundableContractController::class)
        ->group(function () {
            Route::get('/contracts', 'index')->middleware('permission:analytics.view')->name('contracts.index');
            // Body-based confirm (avoids hosting WAF 403 on POST .../contracts/{uuid})
            Route::post('/contracts/confirm', 'confirm')->middleware('permission:returned_request.retrieve')->name('contracts.confirm');
            Route::get('/contracts/{uuid}', 'show')->middleware('permission:analytics.view')->name('contracts.show');
            Route::match(['post', 'put', 'patch'], '/contracts/{uuid}', 'update')->middleware('permission:analytics.edit')->name('contracts.update');
        });

    // Employee-recorded contract payments (ClickPay link on create)
    Route::prefix('contract-paid-by-employees')->name('contract-paid-by-employees.')
        ->controller(ContractPaidByEmployeeController::class)
        ->group(function () {
            Route::get('/', 'index')->middleware('permission:contract_payments.view')->name('index');
            Route::post('/', 'store')->middleware('permission:contract_payments.create')->name('store');
            Route::get('/{id}/payment-link', 'paymentLink')->whereNumber('id')->middleware('permission:contract_payments.view')->name('payment-link');
            Route::get('/{id}', 'show')->whereNumber('id')->middleware('permission:contract_payments.view')->name('show');
        });

    // Received contract: which employee received the contract (`received_contracts` table)
    Route::prefix('received-contracts')->name('received-contracts.')->controller(ReceivedContractController::class)->group(function () {
        Route::post('/', 'store')->middleware('permission:all_requests.edit')->name('store');
        Route::patch('{contractId}', 'update')->whereNumber('contractId')->middleware('permission:all_requests.edit')->name('update');
        Route::get('{contractId}', 'show')->whereNumber('contractId')->middleware('permission:all_requests.view')->name('show');
    });

    // Contracts lists
    Route::prefix('contracts')->name('contracts.')->controller(OrderController::class)->group(function () {
        Route::get('/draft', 'draftContracts')->middleware('permission:incomplete_request.view')->name('draft');
        Route::get('/draft/status/{statusId}', 'draftByStatus')->whereNumber('statusId')->middleware('permission:incomplete_request.view')->name('draft-by-status');
        Route::get('/completed', 'complete')->middleware('permission:completed_request.view')->name('completed');
        Route::get('/completed-draft', 'completedAndDraft')->middleware('permission:completed_request.view')->name('completed-draft');
        Route::get('/received', 'receivedOrders')->middleware('permission:request_classification.view')->name('received');
    });

    // Orders Management
    Route::prefix('orders')->name('orders.')->controller(OrderController::class)->group(function () {
        Route::get('/', 'orders')->middleware('permission:all_requests.view')->name('index');
        Route::get('/return', 'returnOrders')->middleware('permission:returned_request.view')->name('return');
        Route::get('/received', 'receivedOrders')->middleware('permission:request_classification.view')->name('received');
        Route::get('/status/{statusId}', 'byStatus')->whereNumber('statusId')->middleware('permission:request_classification.view')->name('by-status');
        Route::get('/completed', 'complete')->middleware('permission:completed_request.view')->name('completed');
        Route::get('/draft', 'draftContracts')->middleware('permission:incomplete_request.view')->name('draft');
        Route::get('/draft/status/{statusId}', 'draftByStatus')->whereNumber('statusId')->middleware('permission:incomplete_request.view')->name('draft-by-status');
        Route::get('/completed-draft', 'completedAndDraft')->middleware('permission:completed_request.view')->name('completed-draft');
        Route::get('/incomplete/list', 'incomplete')->middleware('permission:incomplete_request.view')->name('incomplete');
        Route::get('/complete/list', 'complete')->middleware('permission:completed_request.view')->name('complete');
        Route::get('/{id}', 'show')->whereNumber('id')->middleware('permission:all_requests.view')->name('show');
        Route::post('/{id}', 'update')->whereNumber('id')->middleware('permission:all_requests.edit')->name('update');
        Route::post('/{id}/status', 'updateStatus')->whereNumber('id')->middleware('permission:all_requests.edit')->name('update-status');
        Route::post('/{id}/contract-status', 'updateContractStatus')->whereNumber('id')->middleware('permission:all_requests.edit')->name('update-contract-status');
        Route::post('/{id}/draft-contract-status', 'updateDraftContractStatus')->whereNumber('id')->middleware('permission:all_requests.edit')->name('update-draft-contract-status');
        Route::post('/{id}/return-contract-status', 'updateReturnContractAcceptance')->whereNumber('id')->middleware('permission:returned_request.retrieve')->name('return-contract-status');
        // حذف طلب — صلاحية حذف صريحة (كان «تعديل» فقط)، ويرفض حذف طلب مدفوع. (DASHBOARD-3)
        Route::post('/{id}/delete', 'destroy')->whereNumber('id')->middleware('permission:all_requests.delete')->name('destroy');
    });

    // Contract comments (employee-authenticated)
    Route::prefix('orders/{contractId}/comments')
        ->name('orders.comments.')
        ->controller(ContractCommentController::class)
        ->middleware('permission:all_requests.edit')
        ->group(function () {
            Route::get('/', 'index')->whereNumber('contractId')->withoutMiddleware('permission:all_requests.edit')->middleware('permission:all_requests.view')->name('index');
            Route::post('/', 'store')->whereNumber('contractId')->name('store');
            Route::post('/{commentId}', 'update')->whereNumber('contractId')->whereNumber('commentId')->name('update');
            Route::post('/{commentId}/delete', 'destroy')->whereNumber('contractId')->whereNumber('commentId')->name('destroy');
        });

    // Contract units (multi-unit via contract_units)
    Route::prefix('orders/{contractId}/units')
        ->name('orders.units.')
        ->controller(ContractUnitController::class)
        ->middleware('permission:all_requests.edit')
        ->group(function () {
            Route::get('/', 'index')->whereNumber('contractId')->withoutMiddleware('permission:all_requests.edit')->middleware('permission:all_requests.view')->name('index');
            Route::post('/', 'store')->whereNumber('contractId')->name('store');
            Route::post('/sync', 'sync')->whereNumber('contractId')->name('sync');
            Route::get('/{unitId}', 'show')->whereNumber('contractId')->whereNumber('unitId')->withoutMiddleware('permission:all_requests.edit')->middleware('permission:all_requests.view')->name('show');
            Route::post('/{unitId}', 'update')->whereNumber('contractId')->whereNumber('unitId')->name('update');
            Route::post('/{unitId}/delete', 'destroy')->whereNumber('contractId')->whereNumber('unitId')->name('destroy');
        });

    // Orders Filtering
    Route::prefix('orders')->name('orders.')->controller(FilterContract::class)->middleware('permission:all_requests.view')->group(function () {
        Route::get('/filter', 'filter')->name('filter');
    });

    // Contract Statuses Management
    Route::prefix('contract-statuses')->name('contract-statuses.')->controller(ContractStatusController::class)->group(function () {
        Route::get('/active', 'active')->middleware('permission:contract_statuses.view')->name('active');
        Route::get('/', 'index')->middleware('permission:contract_statuses.view')->name('index');
        Route::post('/', 'store')->middleware('permission:contract_statuses.create')->name('store');
        Route::get('/{id}', 'show')->middleware('permission:contract_statuses.view')->name('show');
        Route::post('/{id}', 'update')->middleware('permission:contract_statuses.edit')->name('update');
        Route::post('/{id}/delete', 'destroy')->middleware('permission:contract_statuses.delete')->name('destroy');
    });

    // Draft Contract Statuses (مسودات) — same shape as contract-statuses
    Route::prefix('draft-contract-statuses')->name('draft-contract-statuses.')->controller(DraftContractStatusController::class)->group(function () {
        Route::get('/active', 'active')->middleware('permission:draft_contract_statuses.view')->name('active');
        Route::post('/sync', 'syncFromContractStatuses')->middleware('permission:draft_contract_statuses.edit')->name('sync');
        Route::get('/', 'index')->middleware('permission:draft_contract_statuses.view')->name('index');
        Route::post('/', 'store')->middleware('permission:draft_contract_statuses.create')->name('store');
        Route::get('/{id}', 'show')->whereNumber('id')->middleware('permission:draft_contract_statuses.view')->name('show');
        Route::post('/{id}', 'update')->whereNumber('id')->middleware('permission:draft_contract_statuses.edit')->name('update');
        Route::post('/{id}/delete', 'destroy')->whereNumber('id')->middleware('permission:draft_contract_statuses.delete')->name('destroy');
    });

    // Contract WhatsApp Management
    Route::prefix('contract-whatsapp')->name('contract-whatsapp.')->controller(ContractWhatsAppController::class)->group(function () {
        Route::get('/', 'index')->middleware('permission:contract_whatsapp.view')->name('index');
        Route::post('/complete', 'storeComplete')->middleware('permission:completed_whatsapp_request.create')->name('store.complete');
        Route::post('/incomplete', 'storeIncomplete')->middleware('permission:incomplete_whatsapp_request.create')->name('store.incomplete');
    });

    // Popup Contract Management
    Route::prefix('popup-contracts')->name('popup-contracts.')->controller(PopupContractController::class)->group(function () {
        Route::get('/', 'index')->middleware('permission:popup_contracts.view')->name('index');
        Route::post('/', 'store')->middleware('permission:popup_contracts.create')->name('store');
        Route::get('/{id}', 'show')->whereNumber('id')->middleware('permission:popup_contracts.view')->name('show');
        Route::post('/{id}', 'update')->whereNumber('id')->middleware('permission:popup_contracts.edit')->name('update');
        Route::post('/{id}/delete', 'destroy')->whereNumber('id')->middleware('permission:popup_contracts.delete')->name('destroy');
    });
});
