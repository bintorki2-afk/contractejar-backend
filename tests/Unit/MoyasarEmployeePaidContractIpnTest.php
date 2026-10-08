<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Models\ContractPaidByEmployee;
use App\Models\Employee;
use App\Models\Payment;
use App\Services\MoyasarPaymentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MoyasarEmployeePaidContractIpnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createMinimalSchema();

        // هذه الاختبارات تختبر مسار IPN/المزامنة بمخطط مصغّر بلا جداول الأسعار؛ المستحق
        // للعقد يُثبَّت 574 ر.س (= مبلغ الدفعات المزيّفة) — مطابقة المبلغ مختبرة في
        // PaymentAmountVerificationTest بالمخطط الكامل.
        $this->app->instance(\App\Services\PaymentAmountVerifier::class, new class extends \App\Services\PaymentAmountVerifier {
            protected function expectedForContract(\App\Models\Contract $contract): ?float
            {
                return 574.0;
            }
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('contract_paid_by_employees');
        Schema::dropIfExists('employees');

        parent::tearDown();
    }

    private function createMinimalSchema(): void
    {
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('contract_paid_by_employees');
        Schema::dropIfExists('employees');

        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('contract_paid_by_employees', function (Blueprint $table): void {
            $table->id();
            $table->string('contract_uuid')->nullable();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('customer_mobile', 32);
            $table->decimal('amount', 10, 2)->default(0);
            $table->boolean('is_paid')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('contract_uuid')->nullable();
            $table->string('tran_currency')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_brand')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('payment_date')->nullable();
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->string('app_or_web')->nullable();
            $table->timestamps();
        });
    }

    public function test_ipn_marks_employee_paid_contract_as_paid_without_contract_row(): void
    {
        config([
            'services.moyasar.secret_key' => 'test_secret',
            'services.moyasar.base_url' => 'https://api.moyasar.com',
        ]);

        Http::fake([
            'https://api.moyasar.com/v1/payments/pay_test' => Http::response([
                'id' => 'pay_test',
                'status' => 'paid',
                'amount' => 50000,
                'currency' => 'SAR',
                'source' => ['type' => 'creditcard'],
                'metadata' => ['contract_uuid' => '123456'],
            ], 200),
        ]);

        $employee = Employee::query()->create([
            'name' => 'IPN Employee',
            'email' => 'ipn-employee@test.local',
            'password' => Hash::make('secret'),
            'is_active' => true,
        ]);

        ContractPaidByEmployee::query()->create([
            'contract_uuid' => '123456',
            'employee_id' => $employee->id,
            'customer_mobile' => '0512345678',
            'amount' => 500,
            'is_paid' => false,
        ]);

        $service = app(MoyasarPaymentService::class);
        $service->processIpn(new Request([
            'id' => 'pay_test',
            'status' => 'paid',
        ]), '123456');

        $this->assertTrue(
            (bool) ContractPaidByEmployee::query()->where('contract_uuid', '123456')->value('is_paid')
        );

        $this->assertDatabaseHas('payments', [
            'contract_uuid' => '123456',
            'status' => 'success',
        ]);
    }

    public function test_payment_status_payload_works_for_employee_paid_contract_without_contract_row(): void
    {
        config([
            'services.moyasar.secret_key' => 'test_secret',
            'services.moyasar.base_url' => 'https://api.moyasar.com',
        ]);

        $employee = Employee::query()->create([
            'name' => 'Status Employee',
            'email' => 'status-employee@test.local',
            'password' => Hash::make('secret'),
            'is_active' => true,
        ]);

        ContractPaidByEmployee::query()->create([
            'contract_uuid' => '654321',
            'employee_id' => $employee->id,
            'customer_mobile' => '0511111111',
            'amount' => 300,
            'is_paid' => true,
            'notes' => 'paid in branch',
        ]);

        Payment::query()->create([
            'amount' => 300,
            'contract_uuid' => '654321',
            'tran_currency' => 'SAR',
            'status' => 'success',
            'payment_date' => now(),
        ]);

        $payload = app(MoyasarPaymentService::class)->paymentStatusPayload('654321', 'success');

        $this->assertSame('success', $payload['result']);
        $this->assertSame('success', $payload['resolved_result']);
        $this->assertSame('654321', $payload['contract_uuid']);
        $this->assertNull($payload['contract_id']);
        $this->assertFalse($payload['is_completed']);
        $this->assertTrue($payload['payment_confirmed']);
        $this->assertTrue($payload['employee_paid_record']['is_paid']);
        $this->assertArrayNotHasKey('customer_mobile', $payload['employee_paid_record']);
        $this->assertArrayNotHasKey('notes', $payload['employee_paid_record']);
    }

    public function test_numeric_contract_id_is_not_accepted_as_payment_uuid(): void
    {
        $contract = Contract::query()->create([
            'uuid' => '654321',
            'is_completed' => false,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        app(MoyasarPaymentService::class)->paymentStatusPayload((string) $contract->id, 'return');
    }

    public function test_completed_contract_cannot_request_payment_again(): void
    {
        $contract = Contract::query()->create([
            'is_completed' => true,
        ]);

        Payment::query()->create([
            'amount' => 500,
            'contract_uuid' => (string) $contract->uuid,
            'tran_currency' => 'SAR',
            'status' => 'success',
            'payment_date' => now(),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(trans('api.completed_contract'));

        app(MoyasarPaymentService::class)->requestPaymentRedirectUrl((string) $contract->uuid, 500);
    }

    public function test_stale_completed_flag_without_success_payment_allows_retry(): void
    {
        $contract = Contract::query()->create([
            'is_completed' => true,
        ]);

        Payment::query()->create([
            'amount' => 500,
            'contract_uuid' => (string) $contract->uuid,
            'tran_currency' => 'SAR',
            'status' => 'failed',
            'payment_date' => now(),
        ]);

        config([
            'services.moyasar.secret_key' => 'test_secret',
            'services.moyasar.base_url' => 'https://api.moyasar.com',
        ]);

        Http::fake([
            'https://api.moyasar.com/v1/invoices' => Http::response([
                'id' => 'inv_retry',
                'url' => 'https://moyasar.test/pay/inv_retry',
                'status' => 'initiated',
            ], 201),
        ]);

        $result = app(MoyasarPaymentService::class)->requestPaymentRedirectUrl(
            (string) $contract->uuid,
            500
        );

        $this->assertArrayHasKey('payment_url', $result);
        $this->assertFalse((bool) $contract->fresh()->is_completed);
    }

    public function test_failed_ipn_reverts_stale_completed_flag(): void
    {
        $contract = Contract::query()->create([
            'is_completed' => true,
        ]);

        app(MoyasarPaymentService::class)->processIpn(new Request([
            'id' => 'pay_failed_revert',
            'status' => 'failed',
            'amount' => 50000,
            'currency' => 'SAR',
            'metadata' => ['contract_uuid' => (string) $contract->uuid],
        ]), (string) $contract->uuid);

        $this->assertFalse((bool) $contract->fresh()->is_completed);
        $this->assertDatabaseHas('payments', [
            'contract_uuid' => (string) $contract->uuid,
            'status' => 'failed',
        ]);
    }

    public function test_untrusted_paid_status_without_gateway_does_not_complete_contract(): void
    {
        config([
            'services.moyasar.secret_key' => 'test_secret',
            'services.moyasar.base_url' => 'https://api.moyasar.com',
        ]);

        $contract = Contract::query()->create([
            'is_completed' => false,
        ]);

        Http::fake([
            'https://api.moyasar.com/v1/*' => Http::response([], 404),
        ]);

        app(MoyasarPaymentService::class)->processIpn(new Request([
            'status' => 'paid',
            'amount' => 50000,
        ]), (string) $contract->uuid);

        $this->assertFalse((bool) $contract->fresh()->is_completed);
        $this->assertSame(
            0,
            Payment::query()->where('contract_uuid', (string) $contract->uuid)->where('status', 'success')->count()
        );
    }

    public function test_contract_with_successful_payment_cannot_request_payment_again(): void
    {
        $contract = Contract::query()->create([
            'is_completed' => false,
        ]);

        Payment::query()->create([
            'amount' => 500,
            'contract_uuid' => (string) $contract->uuid,
            'tran_currency' => 'SAR',
            'status' => 'success',
            'payment_date' => now(),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(trans('api.completed_contract'));

        app(MoyasarPaymentService::class)->requestPaymentRedirectUrl((string) $contract->uuid, 500);
    }

    public function test_duplicate_paid_ipn_does_not_create_duplicate_success_payment_rows(): void
    {
        config([
            'services.moyasar.secret_key' => 'test_secret',
            'services.moyasar.base_url' => 'https://api.moyasar.com',
        ]);

        Http::fake([
            'https://api.moyasar.com/v1/payments/pay_duplicate' => Http::response([
                'id' => 'pay_duplicate',
                'status' => 'paid',
                'amount' => 50000,
                'currency' => 'SAR',
                'source' => ['type' => 'creditcard'],
                'metadata' => ['contract_uuid' => '999999'],
            ], 200),
        ]);

        $employee = Employee::query()->create([
            'name' => 'Duplicate IPN Employee',
            'email' => 'duplicate-ipn@test.local',
            'password' => Hash::make('secret'),
            'is_active' => true,
        ]);

        Contract::query()->create([
            'uuid' => '999999',
            'is_completed' => true,
        ]);

        ContractPaidByEmployee::query()->create([
            'contract_uuid' => '999999',
            'employee_id' => $employee->id,
            'customer_mobile' => '0555555555',
            'amount' => 500,
            'is_paid' => false,
        ]);

        Payment::query()->create([
            'amount' => 500,
            'contract_uuid' => '999999',
            'tran_currency' => 'SAR',
            'status' => 'success',
            'payment_date' => now(),
        ]);

        app(MoyasarPaymentService::class)->processIpn(new Request([
            'id' => 'pay_duplicate',
            'status' => 'paid',
        ]), '999999');

        $this->assertSame(
            1,
            Payment::query()->where('contract_uuid', '999999')->where('status', 'success')->count()
        );
        $this->assertTrue(
            (bool) ContractPaidByEmployee::query()->where('contract_uuid', '999999')->value('is_paid')
        );
    }

    public function test_failed_status_from_return_link_is_saved_in_database(): void
    {
        app(MoyasarPaymentService::class)->processIpn(new Request([
            'id' => 'pay_failed',
            'status' => 'failed',
            'amount' => 50000,
            'currency' => 'SAR',
            'metadata' => ['contract_uuid' => '444444'],
        ]), '444444');

        $this->assertDatabaseHas('payments', [
            'contract_uuid' => '444444',
            'status' => 'failed',
        ]);
    }

    public function test_paid_ipn_without_name_uses_contract_name_fallback(): void
    {
        config([
            'services.moyasar.secret_key' => 'test_secret',
            'services.moyasar.base_url' => 'https://api.moyasar.com',
        ]);

        $contract = Contract::query()->create([
            'is_completed' => false,
        ]);

        Http::fake([
            'https://api.moyasar.com/v1/payments/pay_without_name' => Http::response([
                'id' => 'pay_without_name',
                'status' => 'paid',
                'amount' => 57400,
                'currency' => 'SAR',
                'metadata' => ['contract_uuid' => (string) $contract->uuid],
                'source' => [],
            ], 200),
        ]);

        app(MoyasarPaymentService::class)->processIpn(new Request([
            'id' => 'pay_without_name',
            'status' => 'paid',
        ]), (string) $contract->uuid);

        $this->assertDatabaseHas('payments', [
            'contract_uuid' => (string) $contract->uuid,
            'name' => 'Contract ' . $contract->uuid,
            'payment_method' => 'moyasar',
            'amount' => 574.00,
            'status' => 'success',
        ]);
    }

    public function test_gateway_sync_by_metadata_marks_contract_as_completed(): void
    {
        config([
            'services.moyasar.secret_key' => 'test_secret',
            'services.moyasar.base_url' => 'https://api.moyasar.com',
        ]);

        $contract = Contract::query()->create([
            'is_completed' => false,
        ]);

        Http::fake([
            'https://api.moyasar.com/v1/invoices*' => Http::response([
                'invoices' => [
                    [
                        'id' => 'invoice_paid_1',
                        'status' => 'paid',
                        'amount' => 57400,
                        'currency' => 'SAR',
                        'created_at' => now()->toIso8601String(),
                        'metadata' => ['contract_uuid' => (string) $contract->uuid],
                        'payments' => [
                            [
                                'id' => 'gateway_paid_1',
                                'status' => 'paid',
                                'amount' => 57400,
                                'currency' => 'SAR',
                                'source' => ['type' => 'creditcard'],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://api.moyasar.com/v1/payments*' => Http::response([
                'payments' => [],
            ], 200),
        ]);

        $payload = app(MoyasarPaymentService::class)->syncGatewayPaymentStatus((string) $contract->uuid);

        $this->assertTrue($payload['synced']);
        $this->assertSame('success', $payload['status']);
        $this->assertTrue((bool) $contract->fresh()->is_completed);
        $this->assertDatabaseHas('payments', [
            'contract_uuid' => (string) $contract->uuid,
            'status' => 'success',
        ]);
    }

    public function test_gateway_sync_by_invoice_id_marks_contract_as_completed(): void
    {
        config([
            'services.moyasar.secret_key' => 'test_secret',
            'services.moyasar.base_url' => 'https://api.moyasar.com',
        ]);

        $contract = Contract::query()->create([
            'is_completed' => false,
        ]);

        Http::fake([
            'https://api.moyasar.com/v1/invoices/invoice_from_redirect' => Http::response([
                'id' => 'invoice_from_redirect',
                'status' => 'paid',
                'amount' => 57400,
                'currency' => 'SAR',
                'metadata' => ['contract_uuid' => (string) $contract->uuid],
                'payments' => [
                    [
                        'id' => 'gateway_paid_redirect',
                        'status' => 'paid',
                        'amount' => 57400,
                        'currency' => 'SAR',
                        'source' => ['type' => 'creditcard'],
                    ],
                ],
            ], 200),
        ]);

        $payload = app(MoyasarPaymentService::class)->syncGatewayPaymentStatus(
            (string) $contract->uuid,
            null,
            'invoice_from_redirect'
        );

        $this->assertTrue($payload['synced']);
        $this->assertSame('success', $payload['status']);
        $this->assertTrue((bool) $contract->fresh()->is_completed);
    }

    public function test_error_page_payload_resolves_to_success_when_gateway_confirms_paid(): void
    {
        config([
            'services.moyasar.secret_key' => 'test_secret',
            'services.moyasar.base_url' => 'https://api.moyasar.com',
        ]);

        $contract = Contract::query()->create([
            'is_completed' => false,
        ]);

        Http::fake([
            'https://api.moyasar.com/v1/invoices/invoice_paid_after_redirect' => Http::response([
                'id' => 'invoice_paid_after_redirect',
                'status' => 'paid',
                'amount' => 57400,
                'currency' => 'SAR',
                'metadata' => ['contract_uuid' => (string) $contract->uuid],
                'payments' => [
                    [
                        'id' => 'gateway_paid_after_redirect',
                        'status' => 'paid',
                        'amount' => 57400,
                        'currency' => 'SAR',
                        'source' => ['type' => 'creditcard'],
                    ],
                ],
            ], 200),
        ]);

        $payload = app(MoyasarPaymentService::class)->paymentStatusPayload(
            (string) $contract->uuid,
            'error',
            null,
            'invoice_paid_after_redirect'
        );

        $this->assertSame('error', $payload['result']);
        $this->assertSame('success', $payload['resolved_result']);
        $this->assertTrue($payload['payment_confirmed']);
        $this->assertTrue((bool) $contract->fresh()->is_completed);
    }

    public function test_paid_gateway_payment_for_another_contract_does_not_complete_this_one(): void
    {
        config([
            'services.moyasar.secret_key' => 'test_secret',
            'services.moyasar.base_url' => 'https://api.moyasar.com',
        ]);

        $victim = Contract::query()->create(['is_completed' => false]);
        $attacker = Contract::query()->create(['is_completed' => false]);

        Http::fake([
            'https://api.moyasar.com/v1/payments/pay_other_contract' => Http::response([
                'id' => 'pay_other_contract',
                'status' => 'paid',
                'amount' => 10000,
                'currency' => 'SAR',
                'metadata' => ['contract_uuid' => (string) $attacker->uuid],
                'source' => ['type' => 'creditcard'],
            ], 200),
            'https://api.moyasar.com/v1/invoices*' => Http::response(['invoices' => []], 200),
            'https://api.moyasar.com/v1/payments*' => Http::response(['payments' => []], 200),
        ]);

        app(MoyasarPaymentService::class)->processIpn(new Request([
            'id' => 'pay_other_contract',
            'status' => 'paid',
        ]), (string) $victim->uuid);

        $this->assertFalse((bool) $victim->fresh()->is_completed);
        $this->assertFalse(
            Payment::query()
                ->where('contract_uuid', (string) $victim->uuid)
                ->where('status', 'success')
                ->exists()
        );
    }
}
