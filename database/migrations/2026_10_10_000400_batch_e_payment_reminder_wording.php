<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (هـ) — متابعة #1: قالب تذكير الدفع كان يذكر «مسودة عقدك» (مرحلة أُلغيت).
 * يُحدَّث فقط إذا لم يعدّله المالك (النص الافتراضي القديم كما هو).
 */
return new class extends Migration
{
    private const OLD = "مرحباً {name}\nطلبك رقم {order} جاهز للدفع ({amount} ر.س). ادفع الآن لنبدأ إعداد مسودة عقدك: {link}";

    private const NEW = "مرحباً {name}\nطلبك رقم {order} جاهز للدفع ({amount} ر.س). ادفع الآن لنبدأ توثيق عقدك: {link}";

    public function up(): void
    {
        if (! Schema::hasTable('message_templates')) {
            return;
        }
        DB::table('message_templates')->where('key', 'payment_reminder')->where('channel', 'whatsapp')
            ->where('body', self::OLD)->update(['body' => self::NEW, 'updated_at' => now()]);

        if (Schema::hasTable('contract_statuses') && Schema::hasColumn('contract_statuses', 'status_key')) {
            DB::table('contract_statuses')->where('status_key', 'whatsapp_draft')
                ->update(['is_active' => 0, 'description' => 'حالة قديمة (أُلغيت) — لا تُستخدم']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('message_templates')) {
            return;
        }
        DB::table('message_templates')->where('key', 'payment_reminder')->where('channel', 'whatsapp')
            ->where('body', self::NEW)->update(['body' => self::OLD, 'updated_at' => now()]);
    }
};
