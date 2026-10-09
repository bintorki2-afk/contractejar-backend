<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (د) 2026-10-09 — ب10: قناة الإرسال في سجل الإشعارات (push = صندوق + Push، whatsapp، sms).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_dispatches') && ! Schema::hasColumn('notification_dispatches', 'channel')) {
            Schema::table('notification_dispatches', function (Blueprint $table) {
                $table->string('channel', 20)->default('push')->after('kind');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_dispatches') && Schema::hasColumn('notification_dispatches', 'channel')) {
            Schema::table('notification_dispatches', function (Blueprint $table) {
                $table->dropColumn('channel');
            });
        }
    }
};
