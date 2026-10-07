<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * خدمة «تغيير المؤجر»: طلب مستقل برقم طلب (6 أرقام) يُدفع عبر Moyasar بنفس آلية العقود
 * (جدول payments بالـ uuid)، ويُتابَع من /track ومن حساب العميل، ويُدار من لوحة التحكم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessor_change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 32)->unique();                 // رقم الطلب الظاهر للعميل
            $table->unsignedBigInteger('user_id')->nullable()->index(); // زائر أو حساب موثّق
            $table->string('mobile', 20)->nullable()->index();    // جوال التواصل (للتتبّع)
            $table->string('old_deed_image');                     // صك المالك القديم (قرص خاص)
            $table->string('new_deed_image');                     // صك المالك الجديد (قرص خاص)
            $table->string('new_owner_id_number', 20);
            $table->string('new_owner_dob', 20);                  // dd/mm/yyyy
            $table->enum('new_owner_dob_type', ['hijri', 'gregorian'])->default('hijri');
            $table->text('notes')->nullable();
            $table->decimal('fee', 10, 2);                        // ثابت وقت الإنشاء (من الإعدادات)
            $table->enum('status', ['pending_payment', 'paid', 'in_progress', 'completed', 'rejected', 'cancelled'])
                ->default('pending_payment')->index();
            $table->text('status_note')->nullable();              // ملاحظة الموظف للعميل
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('platform', 10)->nullable();           // web / app
            $table->boolean('is_delete')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessor_change_requests');
    }
};
