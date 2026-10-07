<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٤ (الموقع والخادم):
 *  - جلسات الزوّار على الموقع (بدون حساب): مستخدم «ضيف» يحمل توكن ويُدمج لاحقاً
 *    في حساب موثّق عبر OTP بنفس رقم الجوال.
 *  - ربط الإشعار بالطلب (contract_id) ليفتح التطبيق/الموقع الطلب مباشرة.
 *  - صفحات إضافية لصورة الصك (JSON) بدل صورة واحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_guest')) {
                $table->boolean('is_guest')->default(false)->after('is_active')->index();
            }
            if (! Schema::hasColumn('users', 'contact_mobile')) {
                // رقم الواتساب الذي يكتبه الزائر في الموقع (ليس رقم الدخول).
                $table->string('contact_mobile', 32)->nullable()->after('mobile')->index();
            }
            if (! Schema::hasColumn('users', 'merged_into_user_id')) {
                $table->unsignedBigInteger('merged_into_user_id')->nullable()->after('is_guest')->index();
            }
        });

        Schema::table('offers', function (Blueprint $table) {
            if (! Schema::hasColumn('offers', 'contract_id')) {
                $table->unsignedBigInteger('contract_id')->nullable()->after('user_id')->index();
            }
        });

        Schema::table('contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('contracts', 'image_instrument_pages')) {
                $table->json('image_instrument_pages')->nullable()->after('image_instrument');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (Schema::hasColumn('contracts', 'image_instrument_pages')) {
                $table->dropColumn('image_instrument_pages');
            }
        });
        Schema::table('offers', function (Blueprint $table) {
            if (Schema::hasColumn('offers', 'contract_id')) {
                $table->dropColumn('contract_id');
            }
        });
        Schema::table('users', function (Blueprint $table) {
            foreach (['merged_into_user_id', 'contact_mobile', 'is_guest'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
