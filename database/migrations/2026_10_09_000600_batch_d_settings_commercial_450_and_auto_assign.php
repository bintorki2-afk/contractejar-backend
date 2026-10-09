<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (د) 2026-10-09:
 *  - قرار المالك: رسوم السنة الإضافية للعقد التجاري = 450 — يُحدَّث صف الإعدادات فقط إذا كان ما زال 250
 *    (أي قيمة أخرى ضبطها المالك من اللوحة لا تُلمس).
 *  - ب13: الإسناد التلقائي للطلبات (تشغيل/إيقاف + الاستراتيجية + مجموعة الموظفين الاختيارية).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        if (Schema::hasColumn('settings', 'doc_fee_commercial_extra_year')) {
            DB::table('settings')->where('doc_fee_commercial_extra_year', 250)->update(['doc_fee_commercial_extra_year' => 450]);
        }

        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'auto_assign_orders')) {
                $table->boolean('auto_assign_orders')->default(false);
            }
            if (! Schema::hasColumn('settings', 'auto_assign_strategy')) {
                $table->string('auto_assign_strategy', 20)->default('round_robin');
            }
            if (! Schema::hasColumn('settings', 'auto_assign_employee_ids')) {
                $table->json('auto_assign_employee_ids')->nullable();
            }
            if (! Schema::hasColumn('settings', 'auto_assign_last_employee_id')) {
                $table->unsignedBigInteger('auto_assign_last_employee_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }
        Schema::table('settings', function (Blueprint $table) {
            foreach (['auto_assign_orders', 'auto_assign_strategy', 'auto_assign_employee_ids', 'auto_assign_last_employee_id'] as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
