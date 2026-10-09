<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (د) 2026-10-09 — ب12: سلة المحذوفات (30 يوماً) للطلبات وطلبات تغيير المؤجر.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['contracts', 'lessor_change_requests'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'trashed_at')) {
                    $t->timestamp('trashed_at')->nullable()->index();
                }
                if (! Schema::hasColumn($table, 'deleted_by')) {
                    $t->unsignedBigInteger('deleted_by')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['contracts', 'lessor_change_requests'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'trashed_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($table.'_trashed_at_index'));
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['trashed_at', 'deleted_by'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $t->dropColumn($column);
                    }
                }
            });
        }
    }
};
