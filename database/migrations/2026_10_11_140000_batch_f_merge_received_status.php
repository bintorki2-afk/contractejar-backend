<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (و) 2026-10-11 — D1: دمج «مستلم» (received) في «مستلم من الموظف» (received_by_employee).
 *  - الطلبات في «مستلم» تُنقل إلى «مستلم من الموظف» مع صف في سجل الحالات وسجل النشاط.
 *  - صف «مستلم» يُعطَّل (is_active=false) ويبقى مفتاحاً تاريخياً (ContractStatus::LEGACY_KEYS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contract_statuses') || ! Schema::hasTable('contracts')) {
            return;
        }

        $hasKey = Schema::hasColumn('contract_statuses', 'status_key');
        $oldRow = $hasKey
            ? DB::table('contract_statuses')->where('status_key', 'received')->first()
            : DB::table('contract_statuses')->where('name', 'مستلم')->first();
        $newRow = $hasKey
            ? DB::table('contract_statuses')->where('status_key', 'received_by_employee')->first()
            : DB::table('contract_statuses')->where('name', 'مستلم من الموظف')->first();

        if ($oldRow === null) {
            return;
        }

        if ($newRow !== null && (int) $newRow->id !== (int) $oldRow->id) {
            $now = now();
            DB::table('contracts')->where('contract_status_id', $oldRow->id)->orderBy('id')
                ->select(['id'])->chunkById(200, function ($rows) use ($oldRow, $newRow, $now) {
                    foreach ($rows as $c) {
                        DB::table('contracts')->where('id', $c->id)->update(['contract_status_id' => $newRow->id]);

                        if (Schema::hasTable('contract_status_histories')) {
                            DB::table('contract_status_histories')->insert([
                                'contract_id' => $c->id,
                                'status' => 'received_by_employee',
                                'status_label' => (string) $newRow->name,
                                'status_type' => 'contract',
                                'status_id' => $newRow->id,
                                'status_color' => $newRow->color ?? null,
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
                                'before' => json_encode(['contract_status_id' => $oldRow->id, 'status_key' => 'received', 'status_name' => $oldRow->name], JSON_UNESCAPED_UNICODE),
                                'after' => json_encode(['contract_status_id' => $newRow->id, 'status_key' => 'received_by_employee', 'status_name' => $newRow->name], JSON_UNESCAPED_UNICODE),
                                'note' => 'دفعة (و): دُمجت حالة «مستلم» في «مستلم من الموظف».',
                                'customer_visible' => false,
                                'customer_label' => null,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }
                    }
                });

            if (Schema::hasColumn('contract_statuses', 'is_active')) {
                DB::table('contract_statuses')->where('id', $oldRow->id)->update([
                    'is_active' => 0,
                    'description' => 'حالة قديمة (دُمجت في «مستلم من الموظف») — لا تُستخدم',
                ]);
            }
        }
    }

    public function down(): void
    {
        // نقل الحالات لا يُعكس (بيانات تاريخية).
    }
};
