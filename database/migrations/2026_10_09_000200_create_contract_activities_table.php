<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (د) 2026-10-09 — ب9: سجل نشاط الطلب (من فعل ماذا ومتى، قبل/بعد) — يظهر في تفاصيل الطلب باللوحة،
 * ونسخة آمنة منه في رحلة العميل.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contract_activities')) {
            return;
        }

        Schema::create('contract_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contract_id')->nullable()->index();
            $table->unsignedBigInteger('lessor_change_request_id')->nullable()->index();
            $table->string('actor_type', 20)->default('employee'); // employee | system | customer
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_name')->nullable();
            $table->string('action', 40)->index();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('note')->nullable();
            $table->boolean('customer_visible')->default(false);
            $table->string('customer_label')->nullable();
            $table->timestamps();
            $table->index(['contract_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_activities');
    }
};
