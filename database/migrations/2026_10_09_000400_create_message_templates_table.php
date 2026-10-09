<?php

use App\Support\MessageTemplateDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (د) 2026-10-09 — ب16: قوالب الرسائل (واتساب/SMS/Push) بمتغيرات {order} {name} {link} {amount}.
 * يُزرع قالب لكل مرحلة/إشعار (لا يلمس القوالب الموجودة).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('message_templates')) {
            Schema::create('message_templates', function (Blueprint $table) {
                $table->id();
                $table->string('key', 60);
                $table->string('channel', 20); // whatsapp | sms | push
                $table->string('title')->nullable();
                $table->text('body');
                $table->string('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['key', 'channel']);
            });
        }

        $now = now();
        foreach (MessageTemplateDefaults::ROWS as $row) {
            $exists = DB::table('message_templates')->where('key', $row['key'])->where('channel', $row['channel'])->exists();
            if (! $exists) {
                DB::table('message_templates')->insert(array_merge($row, ['is_active' => true, 'created_at' => $now, 'updated_at' => $now]));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
