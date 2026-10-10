<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (هـ) 2026-10-10 — E2/E5: الحوالة البنكية + الرسوم الإضافية وفرق السعر.
 *  - payments: kind (original|price_difference|extra_fee|bank_transfer)، contract_id، charge_id،
 *    employee_id (من سجّل الحوالة)، receipt_path (إيصال الحوالة على الـ Volume)، reference، note.
 *  - contract_charges: الرسوم المعلّقة/المدفوعة لكل طلب (فرق سعر تلقائي أو رسوم إضافية).
 *  - settings: bank_name / bank_iban / bank_account_name (قسم «الحوالة البنكية»).
 *  - قالبا الرسائل charge_payment_request و bank_transfer_instructions.
 *  - صلاحيتان جديدتان: payments.record_transfer و payments.add_fee.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (! Schema::hasColumn('payments', 'kind')) {
                    $table->string('kind', 30)->default('original')->index();
                }
                if (! Schema::hasColumn('payments', 'contract_id')) {
                    $table->unsignedBigInteger('contract_id')->nullable()->index();
                }
                if (! Schema::hasColumn('payments', 'charge_id')) {
                    $table->unsignedBigInteger('charge_id')->nullable()->index();
                }
                if (! Schema::hasColumn('payments', 'employee_id')) {
                    $table->unsignedBigInteger('employee_id')->nullable();
                }
                if (! Schema::hasColumn('payments', 'receipt_path')) {
                    $table->string('receipt_path', 500)->nullable();
                }
                if (! Schema::hasColumn('payments', 'reference')) {
                    $table->string('reference', 150)->nullable();
                }
                if (! Schema::hasColumn('payments', 'note')) {
                    $table->text('note')->nullable();
                }
            });

            // تعبئة contract_id للدفعات القديمة (المفتاح uuid مباشرة).
            if (Schema::hasTable('contracts')) {
                $driver = DB::connection()->getDriverName();
                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    DB::statement('UPDATE payments p JOIN contracts c ON c.uuid = p.contract_uuid SET p.contract_id = c.id WHERE p.contract_id IS NULL');
                } else {
                    DB::statement('UPDATE payments SET contract_id = (SELECT c.id FROM contracts c WHERE c.uuid = payments.contract_uuid LIMIT 1) WHERE contract_id IS NULL');
                }
            }
        }

        if (! Schema::hasTable('contract_charges')) {
            Schema::create('contract_charges', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contract_id')->index();
                $table->string('kind', 30); // price_difference | extra_fee
                $table->decimal('amount', 10, 2);
                $table->text('message')->nullable(); // يراها العميل كما هي
                $table->text('internal_reason')->nullable();
                $table->string('status', 20)->default('pending')->index(); // pending | paid | cancelled
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('payment_id')->nullable();
                $table->string('moyasar_payment_id', 150)->nullable();
                $table->string('payment_key', 80)->nullable()->index(); // مفتاح فاتورة Moyasar: chg-{uuid}-{id}
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('settings')) {
            Schema::table('settings', function (Blueprint $table) {
                foreach (['bank_name', 'bank_iban', 'bank_account_name'] as $column) {
                    if (! Schema::hasColumn('settings', $column)) {
                        $table->string($column, 150)->nullable();
                    }
                }
            });
        }

        if (Schema::hasTable('message_templates')) {
            $now = now();
            foreach (\App\Support\MessageTemplateDefaults::ROWS as $row) {
                if (! in_array($row['key'], ['charge_payment_request', 'bank_transfer_instructions'], true)) {
                    continue;
                }
                $exists = DB::table('message_templates')->where('key', $row['key'])->where('channel', $row['channel'])->exists();
                if (! $exists) {
                    DB::table('message_templates')->insert(array_merge($row, ['is_active' => true, 'created_at' => $now, 'updated_at' => $now]));
                }
            }
        }

        if (Schema::hasTable('permissions')) {
            try {
                app(\App\Services\Admin\RolePermissionResolver::class)->syncAllPermissionsFromConfig();
            } catch (\Throwable) {
                // تُزرع عند أول تحميل للصلاحيات.
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_charges');

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                foreach (['kind', 'contract_id', 'charge_id'] as $column) {
                    if (Schema::hasColumn('payments', $column)) {
                        $table->dropIndex([$column]);
                    }
                }
            });
            Schema::table('payments', function (Blueprint $table) {
                foreach (['kind', 'contract_id', 'charge_id', 'employee_id', 'receipt_path', 'reference', 'note'] as $column) {
                    if (Schema::hasColumn('payments', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('settings')) {
            Schema::table('settings', function (Blueprint $table) {
                foreach (['bank_name', 'bank_iban', 'bank_account_name'] as $column) {
                    if (Schema::hasColumn('settings', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('message_templates')) {
            DB::table('message_templates')->whereIn('key', ['charge_payment_request', 'bank_transfer_instructions'])->delete();
        }
    }
};
