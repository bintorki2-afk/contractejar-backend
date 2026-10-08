<?php

namespace Tests\Feature\BatchD;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Setting;
use App\Modules\Users\Models\User;
use App\Support\DocFee;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * قاعدة مشتركة لاختبارات دفعة (د): sqlite في الذاكرة + الأدوار والصلاحيات وكتالوج الحالات.
 */
abstract class BatchDTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
            'services.firebase.disabled' => true,
            'services.moyasar.secret_key' => 'test_secret',
            'services.moyasar.base_url' => 'https://api.moyasar.com',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => 'RoleSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'PermissionSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'ContractStatusSeeder', '--force' => true]);
        Setting::query()->create(['whatsapp' => '966500000000']);
        DocFee::flushSettingsCache();
        ContractStatus::flushKeyCache();
    }

    protected function employee(string $roleName = 'admin', bool $actAs = true): Employee
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $employee = Employee::query()->create([
            'name' => 'موظف '.$roleName,
            'email' => $roleName.uniqid().'@test.local',
            'password' => Hash::make('secret'),
            'is_active' => true,
            'role_id' => $role->id,
            'role' => $role->name,
        ]);
        if ($actAs) {
            Sanctum::actingAs($employee);
        }

        return $employee;
    }

    protected function customer(string $mobile = '0551234567'): User
    {
        return User::query()->create([
            'fname' => 'عميل', 'lname' => 'تجريبي', 'mobile' => $mobile, 'email' => 'c'.uniqid().'@test.local',
            'password' => bcrypt('x'), 'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function contract(array $attributes = [], ?User $user = null): Contract
    {
        $user ??= $this->customer();

        return Contract::query()->create(array_merge([
            'user_id' => $user->id, 'contract_type' => 'housing', 'instrument_type' => 'electronic',
            'duration_preset' => '1_year', 'total_months' => 12, 'step' => 7, 'is_completed' => 0,
            'tenant_mobile' => '0551234567',
        ], $attributes));
    }

    protected function paidContract(array $attributes = [], ?User $user = null): Contract
    {
        return $this->contract(array_merge(['is_completed' => 1], $attributes), $user);
    }

    protected function payment(\App\Models\Contract $contract, float $amount = 249.0, string $gatewayId = 'pay_test'): \App\Models\Payment
    {
        return \App\Models\Payment::query()->create([
            'name' => 'test', 'amount' => $amount, 'payment_date' => now()->toDateString(),
            'contract_uuid' => (string) $contract->uuid, 'tran_currency' => 'SAR', 'payment_method' => 'creditcard',
            'status' => 'success', 'payment_id' => $gatewayId,
        ]);
    }

    protected function statusId(string $key): int
    {
        $id = ContractStatus::idFor($key);
        $this->assertNotNull($id, "status {$key} missing");

        return (int) $id;
    }
}
