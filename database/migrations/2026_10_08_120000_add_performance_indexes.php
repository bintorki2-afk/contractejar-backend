<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فحص (CROSS-10): فهارس للاستعلامات الأكثر تكراراً.
 *  - payments(contract_uuid, status): كل فحص «مدفوع؟» ومبلغ الطلب.
 *  - users(mobile): الدخول بالجوال/OTP والبحث.
 *  - offers(user_id, is_read): عدّاد الإشعارات غير المقروءة وقائمتها.
 *  - contracts(contract_status_id, created_at) و(is_completed, step, updated_at): قوائم اللوحة
 *    والمجدول (الطلبات غير المكتملة/بانتظار الدفع).
 * إضافية فقط (لا تغيّر البيانات)، ومحميّة بفحص وجود الفهرس فتُعاد بأمان؛ down() يسقطها.
 */
return new class extends Migration
{
    /** @return list<array{0:string,1:list<string>,2:string}> */
    private function indexes(): array
    {
        return [
            ['payments', ['contract_uuid', 'status'], 'payments_contract_uuid_status_index'],
            ['users', ['mobile'], 'users_mobile_index'],
            ['offers', ['user_id', 'is_read'], 'offers_user_id_is_read_index'],
            ['contracts', ['contract_status_id', 'created_at'], 'contracts_status_created_index'],
            ['contracts', ['is_completed', 'step', 'updated_at'], 'contracts_completed_step_updated_index'],
        ];
    }

    public function up(): void
    {
        foreach ($this->indexes() as [$table, $columns, $name]) {
            if (! Schema::hasTable($table) || $this->indexExists($table, $name)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue 2;
                }
            }
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        }
    }

    public function down(): void
    {
        foreach ($this->indexes() as [$table, $columns, $name]) {
            if (! Schema::hasTable($table) || ! $this->indexExists($table, $name)) {
                continue;
            }

            // MySQL: قد يعتمد مفتاح أجنبي على هذا الفهرس (العمود الأول) — نضمن فهرساً بديلاً أولاً.
            if (DB::getDriverName() === 'mysql' && ! $this->otherIndexStartsWith($table, $columns[0], $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->index([$columns[0]], $table.'_'.$columns[0].'_fk_index'));
            }

            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
        }
    }

    private function otherIndexStartsWith(string $table, string $column, string $except): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->where('SEQ_IN_INDEX', 1)
            ->where('INDEX_NAME', '!=', $except)
            ->exists();
    }

    private function indexExists(string $table, string $name): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))->contains(fn ($row) => ($row->name ?? null) === $name);
        }

        if ($driver === 'mysql') {
            return DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('INDEX_NAME', $name)
                ->exists();
        }

        return false;
    }
};
