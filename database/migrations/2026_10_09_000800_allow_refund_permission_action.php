<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (د) 2026-10-09 — ب8: إجراء صلاحية جديد «refund» (payments.refund — استرجاع المدفوعات).
 * عمود permissions.action كان ENUM ثابتاً (view/create/edit/delete/retrieve) ⇒ يصبح VARCHAR(30).
 * MySQL/MariaDB: MODIFY مباشر. SQLite (محلي/اختبارات): إعادة بناء الجدول خارج المعاملة.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE permissions MODIFY action VARCHAR(30) NOT NULL');

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        $sql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'permissions')->value('sql');
        if (! str_contains($sql, 'check ("action" in') && ! str_contains(strtolower($sql), 'check ("action" in')) {
            return; // أُعيد بناؤه مسبقاً
        }

        $fkWasOn = (int) (DB::selectOne('PRAGMA foreign_keys')->foreign_keys ?? 0) === 1;
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('CREATE TABLE "permissions_tmp" ("id" integer primary key autoincrement not null, "name" varchar not null, "section" varchar not null, "section_en" varchar, "action" varchar not null, "action_label_ar" varchar not null, "action_label_en" varchar, "description" text, "is_active" tinyint(1) not null default \'1\', "created_at" datetime, "updated_at" datetime)');
        DB::statement('INSERT INTO "permissions_tmp" ("id", "name", "section", "section_en", "action", "action_label_ar", "action_label_en", "description", "is_active", "created_at", "updated_at") SELECT "id", "name", "section", "section_en", "action", "action_label_ar", "action_label_en", "description", "is_active", "created_at", "updated_at" FROM "permissions"');
        DB::statement('DROP TABLE "permissions"');
        DB::statement('ALTER TABLE "permissions_tmp" RENAME TO "permissions"');
        DB::statement('CREATE UNIQUE INDEX "permissions_name_unique" on "permissions" ("name")');
        if ($fkWasOn) {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    public function down(): void
    {
        // لا رجوع إلى ENUM (يحذف صلاحية refund لو رجعنا).
    }
};
