<?php

use App\Support\ContractFrontendStatus;
use App\Support\SchemaCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (د) 2026-10-09 — ب2: مفتاح ثابت لكل حالة طلب (`status_key`) + حالة «مسترجع» مستقلة.
 *
 * - يملأ المفتاح من الاسم العربي (جديد → new، قيد المراجعة → under_review، …).
 * - «قيد المراجعة» لم تعد حالة استرجاع: يُضاف صف «مسترجع» (refunded) إذا لم يوجد.
 * - الطلبات التي كانت في «قيد المراجعة» بسبب طلب استرجاع فعلي (صف في refundable_contracts) تُنقل إلى «مسترجع».
 * - على قاعدة فارغة (تثبيت جديد/اختبارات) لا يُدرج صفوفاً — الـ seeder يزرع الكتالوج كاملاً بنفس الترتيب.
 * آمن لإعادة التشغيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['contract_statuses', 'draft_contract_statuses'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'status_key')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('status_key', 40)->nullable()->after('name');
                    $t->index('status_key');
                });
            }
        }
        SchemaCache::flush();

        foreach (['contract_statuses', 'draft_contract_statuses'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'status_key')) {
                continue;
            }
            $taken = DB::table($table)->whereNotNull('status_key')->pluck('status_key')->all();
            foreach (DB::table($table)->whereNull('status_key')->orderBy('id')->get(['id', 'name']) as $row) {
                $key = ContractFrontendStatus::knownKeyFromName($row->name);
                if ($key === null || in_array($key, $taken, true)) {
                    continue;
                }
                DB::table($table)->where('id', $row->id)->update(['status_key' => $key]);
                $taken[] = $key;
            }
        }

        if (! Schema::hasTable('contract_statuses') || DB::table('contract_statuses')->count() === 0) {
            return;
        }

        $now = now();
        $nextOrder = (int) DB::table('contract_statuses')->max('order') + 1;

        if (! DB::table('contract_statuses')->where('status_key', 'under_review')->exists()) {
            DB::table('contract_statuses')->insert([
                'name' => 'قيد المراجعة',
                'status_key' => 'under_review',
                'color' => '#F59E0B',
                'color_text' => '#000000',
                'description' => 'بعد الدفع وقبل استلام الموظف للطلب',
                'client_explanation' => 'تم استلام دفعتك — فريقنا يراجع بيانات طلبك الآن.',
                'order' => $nextOrder++,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $refundedId = DB::table('contract_statuses')->where('status_key', 'refunded')->value('id');
        if ($refundedId === null) {
            $refundedId = DB::table('contract_statuses')->insertGetId([
                'name' => 'مسترجع',
                'status_key' => 'refunded',
                'color' => '#DC2626',
                'color_text' => '#FFFFFF',
                'description' => 'تم استرجاع مبلغ الطلب للعميل',
                'client_explanation' => 'تم استرجاع مبلغ طلبك.',
                'order' => $nextOrder++,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // «قيد المراجعة» كانت تُستخدم كحالة استرجاع (الرقم 2): الطلبات التي عليها طلب استرجاع فعلي تنتقل لـ «مسترجع».
        $underReviewId = DB::table('contract_statuses')->where('status_key', 'under_review')->value('id');
        if ($underReviewId !== null && Schema::hasTable('refundable_contracts') && Schema::hasTable('contracts')) {
            $ids = DB::table('refundable_contracts')
                ->where(fn ($q) => $q->whereNull('admin_confirmed')->orWhere('admin_confirmed', true)->orWhere('is_refunded', true))
                ->pluck('contract_id')
                ->unique()
                ->all();
            if ($ids !== []) {
                DB::table('contracts')
                    ->whereIn('id', $ids)
                    ->where('contract_status_id', $underReviewId)
                    ->update(['contract_status_id' => $refundedId]);
            }
        }
    }

    public function down(): void
    {
        foreach (['contract_statuses', 'draft_contract_statuses'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'status_key')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropIndex(['status_key']);
                });
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('status_key');
                });
            }
        }
        SchemaCache::flush();
    }
};
