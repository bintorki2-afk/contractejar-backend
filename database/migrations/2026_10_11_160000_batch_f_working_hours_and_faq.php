<?php

use App\Support\WorkingHoursText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (و) 2026-10-11 — D8 (متابعة): ساعات العمل الجديدة تحلّ محل القديمة في الإنتاج.
 *  - settings.working_hours: يُستبدل إن كان فارغاً أو نصاً قديماً واضحاً (9 ص… / بعد منتصف الليل / السبت 9 ص – 2 م).
 *  - settings.working_hours_en (جديد) بالنص الإنجليزي.
 *  - الأسئلة الشائعة (questions) وقوالب الرسائل: تُستبدل عبارات الساعات القديمة الواضحة فقط.
 *  - D7 (متابعة): صلاحيات قسم customer_reviews تُنشأ هنا حتى تظهر في محرر الأدوار ويُمنح للمدير.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            if (! Schema::hasColumn('settings', 'working_hours_en')) {
                Schema::table('settings', fn (Blueprint $t) => $t->string('working_hours_en', 500)->nullable());
            }
            foreach (DB::table('settings')->get(['id', 'working_hours', 'working_hours_en']) as $row) {
                $update = [];
                $current = (string) ($row->working_hours ?? '');
                if (trim($current) === '' || WorkingHoursText::looksOld($current)) {
                    $update['working_hours'] = WorkingHoursText::AR;
                }
                $en = (string) ($row->working_hours_en ?? '');
                if (trim($en) === '' || WorkingHoursText::looksOld($en)) {
                    $update['working_hours_en'] = WorkingHoursText::EN;
                }
                if ($update !== []) {
                    DB::table('settings')->where('id', $row->id)->update($update);
                }
            }
        }

        foreach ([['questions', ['answer_ar', 'answer_en', 'title_ar', 'title_en']], ['message_templates', ['body']]] as [$table, $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $columns = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
            if ($columns === []) {
                continue;
            }
            foreach (DB::table($table)->get(array_merge(['id'], $columns)) as $row) {
                $update = [];
                foreach ($columns as $c) {
                    $new = WorkingHoursText::replaceOld($row->{$c} ?? null);
                    if ($new !== null) {
                        $update[$c] = $new;
                    }
                }
                if ($update !== []) {
                    DB::table($table)->where('id', $row->id)->update($update);
                }
            }
        }

        // D7: قسم صلاحيات «تقييمات العملاء» في محرر الأدوار + منحه للدور manager (إضافة فقط).
        if (Schema::hasTable('permissions')) {
            try {
                app(\App\Services\Admin\RolePermissionResolver::class)->syncAllPermissionsFromConfig();
                $ids = DB::table('permissions')->where('section', 'customer_reviews')->pluck('id')->all();
                $managerId = Schema::hasTable('roles') ? DB::table('roles')->where('name', 'manager')->value('id') : null;
                if ($managerId && $ids !== [] && Schema::hasTable('role_permissions')) {
                    foreach ($ids as $pid) {
                        $exists = DB::table('role_permissions')->where('role_id', $managerId)->where('permission_id', $pid)->exists();
                        if (! $exists) {
                            DB::table('role_permissions')->insert(['role_id' => $managerId, 'permission_id' => $pid, 'created_at' => now(), 'updated_at' => now()]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('customer_reviews permissions sync failed', ['error' => $e->getMessage()]);
            }
        }

        try {
            \App\Support\PublicCache::flush();
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'working_hours_en')) {
            Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('working_hours_en'));
        }
    }
};
