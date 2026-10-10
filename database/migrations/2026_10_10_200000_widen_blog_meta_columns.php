<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * البند 6: Meta Title/Description في عقدي القديم من نوع TEXT، وهنا كانت VARCHAR(255)
 * (والتحقق في اللوحة يسمح بوصف حتى 500 حرف). نوسّعها لـ TEXT عشان ما ينقص أي حرف
 * وقت نقل المقالات القديمة. توسيع فقط — ما يحذف ولا يغيّر أي بيانات. MySQL فقط
 * (sqlite ما يفرض الطول).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('blogs')) {
            return;
        }

        DB::statement('ALTER TABLE `blogs` MODIFY `meta_title` TEXT NULL, MODIFY `meta_description` TEXT NULL');
    }

    public function down(): void
    {
        // لا نرجعها VARCHAR(255): ممكن يقص بيانات مستوردة. التراجع يدوي فقط بعد التأكد.
    }
};
