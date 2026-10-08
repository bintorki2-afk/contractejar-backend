<?php

namespace Tests\Feature\Admin;

use App\Interfaces\PaymentGatewayInterface;
use App\Models\ContractPaidByEmployee;
use App\Models\Employee;
use App\Models\Role;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class ContractPaidByEmployeeStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.url' => 'http://localhost',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        URL::forceRootUrl('http://localhost');

        $this->createMinimalSchema();

        $this->mock(PaymentGatewayInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestPaymentRedirectUrlWithoutContract')
                ->andReturnUsing(function (string $contractUuid, float $amount): array {
                    return [
                        'payment_url' => 'https://pay.test/invoices/test-invoice',
                        'cart_amount' => $amount,
                        'contract_uuid' => $contractUuid,
                    ];
                });
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('contract_paid_by_employees');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('contract_periods');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('roles');

        parent::tearDown();
    }

    private function createMinimalSchema(): void
    {
        Schema::dropIfExists('contract_paid_by_employees');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('contract_periods');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('roles');

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('name');
            $table->decimal('base_salary', 10, 2)->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('password');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('uuid')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->boolean('is_delete')->default(false);
            $table->boolean('is_draft')->default(false);
            $table->string('app_or_web')->nullable();
            $table->timestamps();
        });

        Schema::create('contract_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('period');
            $table->string('note_ar')->nullable();
            $table->string('note_en')->nullable();
            $table->enum('contract_type', ['housing', 'commercial']);
            $table->decimal('price', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contract_paid_by_employees', function (Blueprint $table): void {
            $table->id();
            $table->string('contract_uuid')->nullable();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('customer_mobile', 32);
            $table->enum('contract_type', ['housing', 'commercial'])->nullable();
            $table->foreignId('contract_period_id')->nullable()->constrained('contract_periods')->nullOnDelete();
            $table->string('draft_contract_number', 32)->nullable();
            $table->foreignId('draft_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->decimal('amount', 10, 2)->default(0);
            $table->boolean('is_paid')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function test_employee_can_create_paid_contract_with_generated_uuid_and_payment_link(): void
    {
        $employee = $this->actingEmployee();

        $period = \App\Models\ContractPeriod::query()->create([
            'period' => '1',
            'note_ar' => 'سنة',
            'contract_type' => 'housing',
            'price' => 100,
        ]);

        $response = $this->postJson('/api/admin/contract-paid-by-employees', [
            'customer_mobile' => '0512345678',
            'contract_type' => 'housing',
            'contract_period_id' => $period->id,
            'draft_contract_number' => 'DRAFT-ANY-99',
            'amount' => 500,
            'notes' => 'دفعة من العميل في الفرع',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_url', 'https://pay.test/invoices/test-invoice')
            ->assertJsonPath('data.record.customer_mobile', '0512345678')
            ->assertJsonPath('data.record.amount', 500)
            ->assertJsonPath('data.record.is_paid', false)
            ->assertJsonPath('data.record.notes', 'دفعة من العميل في الفرع')
            ->assertJsonPath('data.record.contract_type', 'housing')
            ->assertJsonPath('data.record.draft_contract_number', 'DRAFT-ANY-99')
            ->assertJsonPath('data.record.draft_contract_id', null);

        $contractUuid = $response->json('data.contract_uuid');
        $this->assertNotEmpty($contractUuid);

        $this->assertDatabaseHas('contract_paid_by_employees', [
            'contract_uuid' => (string) $contractUuid,
            'employee_id' => $employee->id,
            'customer_mobile' => '0512345678',
            'contract_type' => 'housing',
            'contract_period_id' => $period->id,
            'draft_contract_number' => 'DRAFT-ANY-99',
            'draft_contract_id' => null,
            'is_paid' => 0,
            'notes' => 'دفعة من العميل في الفرع',
        ]);
    }

    public function test_employee_can_create_paid_contract_without_notes(): void
    {
        $this->actingEmployee('employee-paid-contract-2@test.local');

        $period = \App\Models\ContractPeriod::query()->create([
            'period' => '2',
            'note_ar' => 'سنتين',
            'contract_type' => 'commercial',
            'price' => 200,
        ]);

        $response = $this->postJson('/api/admin/contract-paid-by-employees', [
            'customer_mobile' => '0598765432',
            'contract_type' => 'commercial',
            'contract_period_id' => $period->id,
            'draft_contract_number' => '000999',
            'amount' => 250.50,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.record.notes', null)
            ->assertJsonPath('data.record.draft_contract_number', '000999');

        $this->assertSame(1, ContractPaidByEmployee::query()->count());
    }

    /** CROSS-7: المبلغ المخصّص مسموح لكن > 0 و ≤ الحد الأعلى، والإنشاء يُسجَّل باسم الموظف. */
    public function test_employee_link_amount_must_be_positive_and_bounded(): void
    {
        $this->actingEmployee('employee-paid-contract-3@test.local');
        config(['services.moyasar.employee_link_max_amount' => 10000]);

        $period = \App\Models\ContractPeriod::query()->create([
            'period' => '1', 'note_ar' => 'سنة', 'contract_type' => 'housing', 'price' => 249,
        ]);
        $payload = fn ($amount) => [
            'customer_mobile' => '0598765432', 'contract_type' => 'housing',
            'contract_period_id' => $period->id, 'draft_contract_number' => '000777', 'amount' => $amount,
        ];

        $this->postJson('/api/admin/contract-paid-by-employees', $payload(0))->assertStatus(422);
        $this->postJson('/api/admin/contract-paid-by-employees', $payload(-5))->assertStatus(422);
        $this->postJson('/api/admin/contract-paid-by-employees', $payload(10000.01))->assertStatus(422);
        $this->assertSame(0, ContractPaidByEmployee::query()->count());

        \Illuminate\Support\Facades\Log::spy();
        $this->postJson('/api/admin/contract-paid-by-employees', $payload(300))->assertCreated();
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'manual amount') && isset($ctx['employee_id']))
            ->once();
    }

    public function test_guest_cannot_create_paid_contract(): void
    {
        $response = $this->postJson('/api/admin/contract-paid-by-employees', [
            'customer_mobile' => '0512345678',
            'contract_type' => 'housing',
            'contract_period_id' => 1,
            'draft_contract_number' => '000001',
            'amount' => 500,
        ]);

        $response->assertUnauthorized();
    }

    private function actingEmployee(string $email = 'employee-paid-contract@test.local'): Employee
    {
        $role = Role::query()->create([
            'name' => 'admin',
            'title_ar' => 'مدير النظام',
            'title_en' => 'System Admin',
            'is_active' => true,
        ]);

        $employee = Employee::query()->create([
            'name' => 'Test Employee',
            'email' => $email,
            'password' => Hash::make('secret'),
            'is_active' => true,
            'role_id' => $role->id,
            'role' => $role->name,
        ]);

        Sanctum::actingAs($employee);

        return $employee;
    }
}
