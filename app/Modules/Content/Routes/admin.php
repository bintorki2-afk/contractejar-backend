<?php

use App\Modules\Content\Controllers\Admin\AdController;
use App\Modules\Content\Controllers\Admin\AppContentOverviewController;
use App\Modules\Content\Controllers\Admin\BlogController;
use App\Modules\Content\Controllers\Admin\ContentPageController;
use App\Modules\Content\Controllers\Admin\CustomerApplicationMessageController;
use App\Modules\Content\Controllers\Admin\FaqController;
use App\Modules\Content\Controllers\Admin\InstructionSectionController;
use App\Modules\Content\Controllers\Admin\MessageAlertController;
use App\Modules\Content\Controllers\Admin\MessageAlertSectionController;
use App\Modules\Content\Controllers\Admin\MessageAlertSectionItemController;
use App\Modules\Content\Controllers\Admin\PageContentController;
use App\Modules\Content\Controllers\Admin\WebsiteImageController;
use Illuminate\Support\Facades\Route;

// FAQ Management
Route::prefix('faqs')->name('faqs.')->controller(FaqController::class)->middleware('auth:sanctum')->group(function () {
    Route::get('/', 'index')->middleware('permission:faqs.view')->name('index');
    Route::post('/', 'store')->middleware('permission:faqs.create')->name('store');
    Route::get('/{id}', 'show')->whereNumber('id')->middleware('permission:faqs.view')->name('show');
    Route::post('/{id}', 'update')->whereNumber('id')->middleware('permission:faqs.edit')->name('update');
    Route::post('/{id}/delete', 'destroy')->whereNumber('id')->middleware('permission:faqs.delete')->name('destroy');
});

// Website images SEO (alt / meta title / meta description)
Route::prefix('website-images')->name('website-images.')
    ->controller(WebsiteImageController::class)
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('/', 'index')->middleware('permission:website_images.view')->name('index');
        Route::post('/', 'store')->middleware('permission:website_images.create')->name('store');
        Route::post('/sync-defaults', 'syncDefaults')->middleware('permission:website_images.create')->name('sync-defaults');
        Route::get('/{id}', 'show')->whereNumber('id')->middleware('permission:website_images.view')->name('show');
        Route::post('/{id}', 'update')->whereNumber('id')->middleware('permission:website_images.edit')->name('update');
        Route::put('/{id}', 'update')->whereNumber('id')->middleware('permission:website_images.edit')->name('update.put');
        Route::delete('/{id}', 'destroy')->whereNumber('id')->middleware('permission:website_images.delete')->name('destroy');
        Route::post('/{id}/delete', 'destroy')->whereNumber('id')->middleware('permission:website_images.delete')->name('destroy.post');
    });

Route::prefix('instruction-sections')->name('instruction-sections.')->controller(InstructionSectionController::class)->middleware('auth:sanctum')->group(function () {
    Route::get('/', 'index')->middleware('permission:instruction_sections.view')->name('index');
    Route::get('/{id}', 'show')->whereNumber('id')->middleware('permission:instruction_sections.view')->name('show');
    Route::post('/{id}', 'update')->whereNumber('id')->middleware('permission:instruction_sections.edit')->name('update');
    Route::post('/{id}/toggle', 'toggle')->whereNumber('id')->middleware('permission:instruction_sections.edit')->name('toggle');
    Route::post('/{id}/delete', 'destroy')->whereNumber('id')->middleware('permission:instruction_sections.delete')->name('destroy');
    Route::post('/{id}/images', 'uploadImage')->whereNumber('id')->middleware('permission:instruction_sections.edit')->name('images.store');
    Route::post('/{id}/images/{imageId}/delete', 'destroyImage')
        ->whereNumber(['id', 'imageId'])
        ->middleware('permission:instruction_sections.delete')
        ->name('images.destroy');
});

// Message alerts (explanatory messages) — sections & items + alerts CRUD
Route::prefix('message-alert-sections')->name('message-alert-sections.')->middleware('auth:sanctum')->group(function () {
    Route::controller(MessageAlertSectionItemController::class)->group(function () {
        Route::get('{sectionId}/items', 'indexForSection')->whereNumber('sectionId')->middleware('permission:message_alerts.view')->name('items.index');
        Route::post('{sectionId}/items', 'storeForSection')->whereNumber('sectionId')->middleware('permission:message_alerts.create')->name('items.store');
    });
    Route::controller(MessageAlertSectionController::class)->group(function () {
        Route::get('/options/list', 'options')->middleware('permission:message_alerts.view')->name('options');
        Route::get('/{audience}/options/list', 'options')
            ->where('audience', 'client|property|employee')
            ->middleware('permission:message_alerts.view')
            ->name('options.audience');
        Route::get('/', 'index')->middleware('permission:message_alerts.view')->name('index');
        Route::post('/', 'store')->middleware('permission:message_alerts.create')->name('store');
        Route::get('/{id}', 'show')->whereNumber('id')->middleware('permission:message_alerts.view')->name('show');
        Route::post('/{id}', 'update')->whereNumber('id')->middleware('permission:message_alerts.edit')->name('update');
        Route::post('/{id}/delete', 'destroy')->whereNumber('id')->middleware('permission:message_alerts.delete')->name('destroy');
    });
});

Route::prefix('message-alert-section-items')->name('message-alert-section-items.')->controller(MessageAlertSectionItemController::class)->middleware('auth:sanctum')->group(function () {
    Route::get('/options/list', 'options')->middleware('permission:message_alerts.view')->name('options');
    Route::get('/{audience}/options/list', 'options')
        ->where('audience', 'client|property|employee')
        ->middleware('permission:message_alerts.view')
        ->name('options.audience');
    Route::get('/', 'index')->middleware('permission:message_alerts.view')->name('index');
    Route::post('/', 'store')->middleware('permission:message_alerts.create')->name('store');
    Route::get('/{id}', 'show')->whereNumber('id')->middleware('permission:message_alerts.view')->name('show');
    Route::post('/{id}', 'update')->whereNumber('id')->middleware('permission:message_alerts.edit')->name('update');
    Route::post('/{id}/delete', 'destroy')->whereNumber('id')->middleware('permission:message_alerts.delete')->name('destroy');
});

Route::prefix('message-alerts')->name('message-alerts.')->controller(MessageAlertController::class)->middleware('auth:sanctum')->group(function () {
    Route::get('/types', 'types')->middleware('permission:message_alerts.view')->name('types');
    Route::get('/all', 'all')->middleware('permission:message_alerts.view')->name('all');

    Route::prefix('{audience}')->where(['audience' => 'client|property|employee'])->group(function () {
        Route::get('/create', 'create')->middleware('permission:message_alerts.view')->name('create.audience');
        Route::get('/', 'index')->middleware('permission:message_alerts.view')->name('index.audience');
        Route::post('/', 'store')->middleware('permission:message_alerts.create')->name('store.audience');
        Route::get('/{id}', 'show')->whereNumber('id')->middleware('permission:message_alerts.view')->name('show.audience');
        Route::post('/{id}', 'update')->whereNumber('id')->middleware('permission:message_alerts.edit')->name('update.audience');
        Route::post('/{id}/delete', 'destroy')->whereNumber('id')->middleware('permission:message_alerts.delete')->name('destroy.audience');
    });

    Route::get('/', 'index')->middleware('permission:message_alerts.view')->name('index');
    Route::post('/', 'store')->middleware('permission:message_alerts.create')->name('store');
    Route::get('/{id}', 'show')->whereNumber('id')->middleware('permission:message_alerts.view')->name('show');
    Route::post('/{id}', 'update')->whereNumber('id')->middleware('permission:message_alerts.edit')->name('update');
    Route::post('/{id}/delete', 'destroy')->whereNumber('id')->middleware('permission:message_alerts.delete')->name('destroy');
});

// Blogs Management
Route::prefix('blogs')->name('blogs.')->controller(BlogController::class)->middleware('auth:sanctum')->group(function () {
    Route::get('/', 'index')->middleware('permission:blogs.view')->name('index');
    Route::post('/', 'store')->middleware('permission:blogs.create')->name('store');
    Route::get('/{id}', 'show')->middleware('permission:blogs.view')->name('show');
    Route::put('/{id}', 'update')->middleware('permission:blogs.edit')->name('update');
    Route::delete('/{id}', 'destroy')->middleware('permission:blogs.delete')->name('destroy');
    Route::post('/{id}/toggle-active', 'toggleActive')->middleware('permission:blogs.edit')->name('toggle-active');
    Route::get('/statistics', 'statistics')->middleware('permission:blogs.view')->name('statistics');
});

// App content dashboard (payment methods, legal pages, customer messages)
Route::prefix('app-content')->name('app-content.')->controller(AppContentOverviewController::class)->middleware(['auth:sanctum', 'permission:app_content.view'])->group(function () {
    Route::get('/overview', 'overview')->name('overview');
});

Route::prefix('customer-messages')->name('customer-messages.')->controller(CustomerApplicationMessageController::class)->middleware('auth:sanctum')->group(function () {
    Route::get('/overview', 'overview')->middleware('permission:app_content.view')->name('overview');
    Route::get('/all', 'all')->middleware('permission:app_content.view')->name('all');
    Route::get('/create', 'createForm')->middleware('permission:app_content.view')->name('create');
    Route::get('/', 'index')->middleware('permission:app_content.view')->name('index');
    Route::post('/', 'store')->middleware('permission:app_content.create')->name('store');
    Route::get('/{id}', 'show')->whereNumber('id')->middleware('permission:app_content.view')->name('show');
    Route::post('/{id}', 'update')->whereNumber('id')->middleware('permission:app_content.edit')->name('update');
    Route::post('/{id}/delete', 'destroy')->whereNumber('id')->middleware('permission:app_content.delete')->name('destroy');
});

Route::prefix('content')->name('content.')->controller(PageContentController::class)->middleware('auth:sanctum')->group(function () {
    Route::get('/legal-pages', 'legalPages')->middleware('permission:app_content.view')->name('legal-pages');
    Route::get('/terms-and-conditions', 'termsAndConditions')->middleware('permission:app_content.view')->name('terms.show');
    Route::post('/terms-and-conditions', 'updateTermsAndConditions')->middleware('permission:app_content.edit')->name('terms.update');
    Route::get('/privacy', 'privacy')->middleware('permission:app_content.view')->name('privacy.show');
    Route::post('/privacy', 'updatePrivacy')->middleware('permission:app_content.edit')->name('privacy.update');
});

Route::prefix('content-pages')->name('content-pages.')->controller(ContentPageController::class)->middleware('auth:sanctum')->group(function () {
    $pages = [
        'home' => 'app_content',
        'about' => 'app_content',
        'blogs' => 'blogs',
        'services' => 'analytics',
        'faq' => 'faqs',
        'faqs' => 'faqs',
    ];

    foreach ($pages as $pageKey => $section) {
        Route::get('/'.$pageKey, 'show')
            ->defaults('pageKey', $pageKey)
            ->middleware('permission:'.$section.'.view')
            ->name($pageKey.'.show');
        Route::post('/'.$pageKey, 'upsert')
            ->defaults('pageKey', $pageKey)
            ->middleware('permission:'.$section.'.edit')
            ->name($pageKey.'.upsert');
    }
});

// Ads Management
Route::prefix('ads')->name('admin-ads.')->controller(AdController::class)->middleware('auth:sanctum')->group(function () {
    Route::get('/', 'index')->middleware('permission:ads.view')->name('index');
    Route::post('/', 'store')->middleware('permission:ads.create')->name('store');
    Route::get('/{id}', 'show')->whereNumber('id')->middleware('permission:ads.view')->name('show');
    Route::post('/{id}', 'update')->whereNumber('id')->middleware('permission:ads.edit')->name('update');
    Route::post('/{id}/delete', 'destroy')->whereNumber('id')->middleware('permission:ads.delete')->name('destroy');
});

// دفعة (و) — D7: تقييمات العملاء + ملخص «4.7 من 3000» (قسم «التسويق والمحتوى»).
Route::prefix('customer-reviews')->name('customer-reviews.')
    ->controller(\App\Modules\Content\Controllers\Admin\CustomerReviewController::class)
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('/', 'index')->middleware('permission:customer_reviews.view')->name('index');
        Route::post('/', 'store')->middleware('permission:customer_reviews.create')->name('store');
        Route::get('/settings', 'settings')->middleware('permission:customer_reviews.view')->name('settings');
        Route::post('/settings', 'updateSettings')->middleware('permission:customer_reviews.edit')->name('settings.update');
        Route::post('/reorder', 'reorder')->middleware('permission:customer_reviews.edit')->name('reorder');
        Route::post('/{id}', 'update')->whereNumber('id')->middleware('permission:customer_reviews.edit')->name('update');
        Route::post('/{id}/delete', 'destroy')->whereNumber('id')->middleware('permission:customer_reviews.delete')->name('destroy');
    });
