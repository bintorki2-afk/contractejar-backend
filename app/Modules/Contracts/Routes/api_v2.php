<?php

use App\Modules\Contracts\Controllers\Api\V2\ContractController as V2ContractController;
use App\Modules\Contracts\Controllers\Api\V2\ContractTrackController;
use App\Modules\Contracts\Controllers\Api\V2\DeedImageController;
use App\Modules\Contracts\Controllers\Api\V2\UncompeleteContractController as V2UncompeleteContractController;
use Illuminate\Support\Facades\Route;

// Deed/instrument images are private: reachable only through a temporary signed URL
// (no auth header needed so <img> tags work, but the signature + expiry are enforced).
Route::get('/contracts/{contract}/deed-image/{field}', [DeedImageController::class, 'show'])
    ->middleware('signed')
    ->name('contracts.deed-image');
Route::get('/contracts/{contract}/deed-page/{index}', [DeedImageController::class, 'page'])
    ->whereNumber('index')
    ->middleware('signed')
    ->name('contracts.deed-page');

// دفعة (و) — D9: ملف مسودة العقد (رابط موقّع مؤقت).
Route::get('/contracts/{contract}/draft-document', [\App\Modules\Contracts\Controllers\Api\V2\DraftDocumentController::class, 'file'])
    ->whereNumber('contract')
    ->middleware('signed')
    ->name('v2.contracts.draft-document');

// تتبّع الطلب بدون حساب (الموقع): رقم الطلب + الجوال. عام ومقيّد.
// تقييد: 10 طلبات بالدقيقة لكل IP (حماية من تخمين أرقام الطلبات/الجوالات).
Route::post('/contract/track', [ContractTrackController::class, 'track'])
    ->middleware('throttle:10,1')
    ->name('v2.contract.track');

Route::middleware(['auth:sanctum', 'ensure.customer'])->group(function () {
    Route::prefix('contract')->name('v2.contract.')->controller(V2ContractController::class)->group(function () {
        Route::post('/start', 'start')->name('start');
        Route::post('/step1', 'step1');
        Route::post('/step2', 'step2');
        Route::post('/step3', 'step3');
        Route::post('/step4', 'step4');
        Route::post('/step5', 'step5');
        Route::post('/step6', 'step6');
        Route::post('/doc-fee', 'docFeePreview');
        Route::post('/draft', 'setDraft')->name('draft');
    });

    // دفعة (و) — D9: «إرسال الطلب والدفع بعد مشاهدة المسودة».
    Route::post('/contract/{uuid}/pay-after-draft', [\App\Modules\Contracts\Controllers\Api\V2\DraftDocumentController::class, 'payAfterDraft'])
        ->middleware('throttle:20,1')->name('v2.contract.pay-after-draft');

    Route::prefix('contract')->name('v2.contract.')->controller(V2UncompeleteContractController::class)->group(function () {
        Route::get('/check-uncompleted-contract', 'checkUncompletedContract');
        Route::post('/uncompleted-contract', 'getUncompletedContractStep');
    });

    Route::controller(V2ContractController::class)->group(function () {
        Route::get('/contracts', 'index');
        Route::get('/contracts/draft', 'drafts');
        Route::get('/contracts/draft/status/{statusId}', 'draftsByStatus')->whereNumber('statusId');
        Route::get('/contracts/status/{statusId}', 'byStatus')->whereNumber('statusId');
        Route::get('/contracts/{id}', 'show');
        Route::delete('/contracts/{id}', 'destroy');
        Route::get('/getContracts/{uuid}', 'getContracts');
        Route::get('/search/{searchTerm}', 'search');
        Route::get('/financial/{uuid}', 'financial');
        Route::get('/finance-summary/{uuid}', 'financial');
    });
});
