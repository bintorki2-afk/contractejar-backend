<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QA-F C1: حذف وحدة من «عقاراتي» كان يحذف صفوف contract_units للطلبات المدفوعة متسلسلاً
 * (ON DELETE CASCADE). نغيّره إلى RESTRICT — والتطبيق يرفض الحذف بـ 422 قبل الوصول للقاعدة.
 * MySQL فقط (على sqlite الحماية في التطبيق).
 */
return new class extends Migration
{
    private const NAME = 'contract_units_real_unit_id_foreign';

    public function up(): void
    {
        $this->apply('RESTRICT');
    }

    public function down(): void
    {
        $this->apply('CASCADE');
    }

    private function apply(string $action): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('contract_units') || ! Schema::hasTable('real_units')) {
            return;
        }

        $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'contract_units')
            ->where('CONSTRAINT_NAME', self::NAME)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();

        if ($exists) {
            DB::statement('ALTER TABLE `contract_units` DROP FOREIGN KEY `'.self::NAME.'`');
        }

        DB::statement(
            'ALTER TABLE `contract_units` ADD CONSTRAINT `'.self::NAME.'` FOREIGN KEY (`real_unit_id`) '
            ."REFERENCES `real_units` (`id`) ON DELETE {$action}"
        );
    }
};
