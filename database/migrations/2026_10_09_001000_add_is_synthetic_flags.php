<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (د) 2026-10-09 — ب19: علامة البيانات الاصطناعية (فحص qa:daily-smoke) لاستبعادها من التقارير والقوائم.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['contracts', 'users'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'is_synthetic')) {
                Schema::table($table, fn (Blueprint $t) => $t->boolean('is_synthetic')->default(false));
            }
        }
    }

    public function down(): void
    {
        foreach (['contracts', 'users'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'is_synthetic')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('is_synthetic'));
            }
        }
    }
};
