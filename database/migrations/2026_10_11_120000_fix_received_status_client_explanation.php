<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QA-F WEB-9: نص «مستلم من الموظف» المعروض للعميل كان تسمية داخلية لحالة أخرى
 * («الحالة الآن - يراجع فريقنا بيانات طلبك.»). يُحدَّث فقط إن لم يعدّله المالك من اللوحة.
 */
return new class extends Migration
{
    private const OLD = 'الحالة الآن - يراجع فريقنا بيانات طلبك.';

    private const NEW = 'استلم موظفنا طلبك ويعمل عليه الآن.';

    public function up(): void
    {
        if (! Schema::hasTable('contract_statuses') || ! Schema::hasColumn('contract_statuses', 'client_explanation')) {
            return;
        }

        DB::table('contract_statuses')
            ->whereIn('client_explanation', [self::OLD, rtrim(self::OLD, '.')])
            ->update(['client_explanation' => self::NEW]);

        if (Schema::hasColumn('contract_statuses', 'description')) {
            DB::table('contract_statuses')
                ->where('description', 'الحالة الآن - يراجع فريقنا بيانات طلبك')
                ->update(['description' => 'استلم الموظف الطلب ويعمل عليه']);
        }
    }

    public function down(): void
    {
        // لا رجوع لنص خاطئ.
    }
};
