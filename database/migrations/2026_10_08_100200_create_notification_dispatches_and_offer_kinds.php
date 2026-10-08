<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة الإصلاحات (ب) 2026-10-08 — ف8 الإشعارات الذكية:
 *  - `notification_dispatches`: سجل الإرسال (منع التكرار لكل نوع/طلب عبر dedupe_key الفريد).
 *  - `offers` (صندوق إشعارات العميل): نوع الإشعار، الرابط الذكي، ربط بطلب تغيير المؤجر، بيانات إضافية، وقت القراءة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_dispatches')) {
            Schema::create('notification_dispatches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('contract_id')->nullable()->index();
                $table->unsignedBigInteger('lessor_change_request_id')->nullable()->index();
                $table->string('kind', 64)->index();
                // مفتاح منع التكرار: "{kind}|contract:{id}" أو "{kind}|lessor:{id}" (+ مؤهل اختياري).
                $table->string('dedupe_key', 160)->nullable()->unique();
                $table->string('title', 255)->nullable();
                $table->text('body')->nullable();
                $table->string('url', 500)->nullable();
                $table->string('push_result', 24)->nullable();
                $table->unsignedInteger('recipients_count')->default(1);
                $table->timestamp('sent_at')->nullable()->index();
                $table->timestamps();
            });
        }

        Schema::table('offers', function (Blueprint $table) {
            if (! Schema::hasColumn('offers', 'kind')) {
                $table->string('kind', 48)->nullable()->after('body')->index();
            }
            if (! Schema::hasColumn('offers', 'url')) {
                $table->string('url', 500)->nullable()->after('kind');
            }
            if (! Schema::hasColumn('offers', 'lessor_change_request_id')) {
                $table->unsignedBigInteger('lessor_change_request_id')->nullable()->after('contract_id')->index();
            }
            if (! Schema::hasColumn('offers', 'data')) {
                $table->json('data')->nullable()->after('url');
            }
            if (! Schema::hasColumn('offers', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('is_read');
            }
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            foreach (['read_at', 'data', 'lessor_change_request_id', 'url', 'kind'] as $column) {
                if (Schema::hasColumn('offers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('notification_dispatches');
    }
};
