<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (و) — مراجعة الأداء: فلتر «مدفوع/غير مدفوع» صار يربط الدفعات بالطلب عبر contract_id (مفهرس)
 * بدل LIKE على uuid. ترحيل دفعة هـ عبّأ contract_id للمفتاح الحرفي فقط؛ هنا نعبّئ الدفعات القديمة
 * بمفتاح «{uuid}-N» (محاولات دفع متعددة). لا يلمس دفعات الرسوم (chg-…) ولا أي صف معبّأ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'contract_id') || ! Schema::hasTable('contracts')) {
            return;
        }

        DB::table('payments')
            ->whereNull('contract_id')
            ->whereNotNull('contract_uuid')
            ->where('contract_uuid', 'like', '%-%')
            ->where('contract_uuid', 'not like', 'chg-%')
            ->orderBy('id')
            ->select(['id', 'contract_uuid'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $base = strstr((string) $row->contract_uuid, '-', true);
                    if ($base === false || $base === '') {
                        continue;
                    }
                    $contractId = DB::table('contracts')->where('uuid', $base)->value('id');
                    if ($contractId !== null) {
                        DB::table('payments')->where('id', $row->id)->whereNull('contract_id')->update(['contract_id' => $contractId]);
                    }
                }
            });
    }

    public function down(): void
    {
        // تعبئة بيانات فقط — لا رجوع (contract_id صحيح ولا يضر).
    }
};
