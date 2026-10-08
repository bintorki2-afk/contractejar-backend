<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة الإصلاحات (ب) 2026-10-08 — ف1 الفاتورة:
 *  - `lines`: لقطة بنود الفاتورة وقت إصدارها (JSON) حتى لا تتغيّر الفواتير القديمة عند تعديل الأسعار.
 *  - `kind` + `lessor_change_request_id` + `user_id`: فاتورة خدمة «تغيير المؤجر» وقائمة فواتير العميل من جدول واحد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'lines')) {
                $table->json('lines')->nullable()->after('total_amount');
            }
            if (! Schema::hasColumn('invoices', 'kind')) {
                $table->string('kind', 32)->default('contract')->after('lines')->index();
            }
            if (! Schema::hasColumn('invoices', 'lessor_change_request_id')) {
                $table->unsignedBigInteger('lessor_change_request_id')->nullable()->after('contract_id')->index();
            }
            if (! Schema::hasColumn('invoices', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('lessor_change_request_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            foreach (['user_id', 'lessor_change_request_id', 'kind', 'lines'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
