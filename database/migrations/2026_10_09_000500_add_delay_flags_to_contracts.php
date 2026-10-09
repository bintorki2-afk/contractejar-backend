<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (د) 2026-10-09 — ب11: علامات تأخير الطلب (يضبطها orders:flag-delays كل 15 دقيقة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('contracts', 'delay_flags')) {
                $table->json('delay_flags')->nullable();
            }
            if (! Schema::hasColumn('contracts', 'delay_flagged_at')) {
                $table->timestamp('delay_flagged_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            foreach (['delay_flags', 'delay_flagged_at'] as $column) {
                if (Schema::hasColumn('contracts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
