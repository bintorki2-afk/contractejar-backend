<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (هـ) 2026-10-10 — E4: طلب مرفق ناقص / تصحيح كعملية متتبّعة.
 *  - contract_data_requests: القسم، البنود، الملاحظة، الحالة، من طلب ومتى، التذكير، الحل.
 *  - employee_notifications: إشعارات اللوحة للموظفين (رد العميل على طلب المرفق، دفع رسوم…).
 *  - قالبا الرسائل data_request و data_request_reminder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contract_data_requests')) {
            Schema::create('contract_data_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contract_id')->index();
                $table->string('section', 20); // lessor | property | tenant
                $table->json('items'); // [{key,label,step,fields[]}]
                $table->text('note')->nullable();
                $table->string('status', 20)->default('pending')->index(); // pending | resolved | cancelled
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('reminded_at')->nullable();
                $table->timestamp('owner_alerted_at')->nullable(); // تنبيه تيليجرام للمالك بعد 72 ساعة (مرة واحدة)
                $table->timestamp('resolved_at')->nullable();
                $table->string('resolved_by', 40)->nullable(); // customer | <employee id>
                $table->json('resolved_fields')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('employee_notifications')) {
            Schema::create('employee_notifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id')->nullable()->index(); // null = كل الموظفين
                $table->unsignedBigInteger('contract_id')->nullable()->index();
                $table->string('kind', 40)->index();
                $table->string('title');
                $table->text('body')->nullable();
                $table->string('url', 500)->nullable();
                $table->json('data')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('message_templates')) {
            $now = now();
            foreach (\App\Support\MessageTemplateDefaults::ROWS as $row) {
                if (! in_array($row['key'], ['data_request', 'data_request_reminder'], true)) {
                    continue;
                }
                $exists = DB::table('message_templates')->where('key', $row['key'])->where('channel', $row['channel'])->exists();
                if (! $exists) {
                    DB::table('message_templates')->insert(array_merge($row, ['is_active' => true, 'created_at' => $now, 'updated_at' => $now]));
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_notifications');
        Schema::dropIfExists('contract_data_requests');
        if (Schema::hasTable('message_templates')) {
            DB::table('message_templates')->whereIn('key', ['data_request', 'data_request_reminder'])->delete();
        }
    }
};
