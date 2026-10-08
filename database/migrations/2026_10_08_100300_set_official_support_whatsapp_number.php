<?php

use App\Support\SupportContact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة الإصلاحات (ب) 2026-10-08 — ف18: رقم الدعم الرسمي 0597500014 (دولي 966597500014).
 * يستبدل القيم التجريبية/الفارغة فقط؛ أي رقم حقيقي آخر ضبطه المالك لا يُلمس.
 */
return new class extends Migration
{
    private const DUMMY = ['966501234567', '0501234567', '501234567', '+966501234567', ''];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (['whatsapp', 'whatsapp_contact', 'whatsapp_contract'] as $column) {
            if (! Schema::hasColumn('settings', $column)) {
                continue;
            }

            DB::table('settings')
                ->where(fn ($q) => $q->whereNull($column)->orWhereIn($column, self::DUMMY))
                ->update([$column => SupportContact::DEFAULT_WHATSAPP]);
        }
    }

    public function down(): void
    {
        // لا رجوع: الرقم الرسمي يبقى.
    }
};
