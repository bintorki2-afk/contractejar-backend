<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (هـ) 2026-10-10 — E3: إزالة مرحلة «إرسال المسودة» نهائياً.
 *  - الطلبات التي كانت في حالة «إرسال مسودة العقد لكم عبر واتساب» (whatsapp_draft) تُنقل إلى
 *    «مستلم من الموظف» مع صف في سجل الحالات وسجل النشاط (الحالة القديمة تبقى بيانات تاريخية فقط).
 *  - صف الحالة القديم يُعطَّل (is_active=false) حتى لا يظهر في قوائم الاختيار.
 *  - قوالب المسودة (stage_draft_sent / draft_sent) تُحذف.
 *  - E1: contracts.ejar_entry_progress — علامات «أدخلتها في إيجار» لكل قسم.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contracts') && ! Schema::hasColumn('contracts', 'ejar_entry_progress')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->json('ejar_entry_progress')->nullable();
            });
        }

        if (! Schema::hasTable('contract_statuses')) {
            return;
        }

        $hasKey = Schema::hasColumn('contract_statuses', 'status_key');
        $draftRow = $hasKey
            ? DB::table('contract_statuses')->where('status_key', 'whatsapp_draft')->first()
            : DB::table('contract_statuses')->whereIn('name', ['إرسال مسودة العقد لكم عبر واتساب', 'ارسال مسودة العقد لكم عبر واتساب'])->first();
        $receivedRow = $hasKey
            ? DB::table('contract_statuses')->where('status_key', 'received_by_employee')->first()
            : DB::table('contract_statuses')->where('name', 'مستلم من الموظف')->first();

        if ($draftRow !== null && $receivedRow !== null) {
            $now = now();
            $contracts = DB::table('contracts')->where('contract_status_id', $draftRow->id)->get(['id', 'uuid']);
            foreach ($contracts as $c) {
                DB::table('contracts')->where('id', $c->id)->update(['contract_status_id' => $receivedRow->id]);

                if (Schema::hasTable('contract_status_histories')) {
                    DB::table('contract_status_histories')->insert([
                        'contract_id' => $c->id,
                        'status' => 'received_by_employee',
                        'status_label' => (string) $receivedRow->name,
                        'status_type' => 'contract',
                        'status_id' => $receivedRow->id,
                        'status_color' => $receivedRow->color ?? null,
                        'source' => 'migration',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if (Schema::hasTable('contract_activities')) {
                    DB::table('contract_activities')->insert([
                        'contract_id' => $c->id,
                        'actor_type' => 'system',
                        'actor_name' => 'النظام',
                        'action' => 'status_changed',
                        'before' => json_encode(['contract_status_id' => $draftRow->id, 'status_key' => 'whatsapp_draft', 'status_name' => $draftRow->name], JSON_UNESCAPED_UNICODE),
                        'after' => json_encode(['contract_status_id' => $receivedRow->id, 'status_key' => 'received_by_employee', 'status_name' => $receivedRow->name], JSON_UNESCAPED_UNICODE),
                        'note' => 'دفعة (هـ): أُلغيت مرحلة إرسال المسودة — الطلب يتابع من «مستلم من الموظف».',
                        'customer_visible' => false,
                        'customer_label' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        if ($draftRow !== null && Schema::hasColumn('contract_statuses', 'is_active')) {
            DB::table('contract_statuses')->where('id', $draftRow->id)->update(['is_active' => 0]);
        }

        if (Schema::hasTable('message_templates')) {
            DB::table('message_templates')->whereIn('key', ['stage_draft_sent', 'draft_sent'])->delete();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('contracts') && Schema::hasColumn('contracts', 'ejar_entry_progress')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->dropColumn('ejar_entry_progress');
            });
        }
        // نقل الحالات لا يُعكس (بيانات تاريخية).
    }
};
