<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (د) 2026-10-09 — ب8: الاسترجاع عبر Moyasar.
 *  - payments: gateway_payment_id (معرّف Moyasar)، refunded_amount، refund_status (partial|full).
 *  - refunds: سجل كل عملية استرجاع (idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'gateway_payment_id')) {
                $table->string('gateway_payment_id', 100)->nullable()->index();
            }
            if (! Schema::hasColumn('payments', 'refunded_amount')) {
                $table->decimal('refunded_amount', 10, 2)->default(0);
            }
            if (! Schema::hasColumn('payments', 'refund_status')) {
                $table->string('refund_status', 20)->nullable();
            }
        });

        if (! Schema::hasTable('refunds')) {
            Schema::create('refunds', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('payment_id')->index();
                $table->unsignedBigInteger('contract_id')->nullable()->index();
                $table->string('contract_uuid', 64)->nullable()->index();
                $table->decimal('amount', 10, 2);
                $table->string('currency', 8)->default('SAR');
                $table->string('gateway_payment_id', 100)->nullable();
                $table->string('moyasar_refund_id', 150)->nullable();
                $table->string('status', 20)->default('pending'); // pending | succeeded | failed
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->text('reason')->nullable();
                $table->text('failure_message')->nullable();
                $table->json('gateway_response')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'gateway_payment_id')) {
                $table->dropIndex(['gateway_payment_id']);
            }
        });
        Schema::table('payments', function (Blueprint $table) {
            foreach (['gateway_payment_id', 'refunded_amount', 'refund_status'] as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
