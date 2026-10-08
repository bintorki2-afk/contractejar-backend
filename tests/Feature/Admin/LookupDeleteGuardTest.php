<?php

namespace Tests\Feature\Admin;

use App\Models\Contract;
use App\Models\Employee;
use App\Modules\Users\Models\User;
use App\Support\LookupUsage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * فحص (CROSS-0): حذف صف مرجعي/موظف مرتبط بطلبات يُرفض بـ 422 ولا تُحذف بيانات العملاء.
 */
class LookupDeleteGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');
        Artisan::call('migrate', ['--force' => true]);

        $admin = Employee::query()->create([
            'name' => 'مدير', 'email' => 'a'.uniqid().'@t.local',
            'password' => Hash::make('x'), 'is_active' => true, 'role' => 'admin',
        ]);
        Sanctum::actingAs($admin);
    }

    private function contract(array $attrs): Contract
    {
        $user = User::query()->create(['email' => uniqid().'@u.local', 'password' => bcrypt('x'), 'is_active' => true]);

        return Contract::query()->create(array_merge(['user_id' => $user->id, 'contract_type' => 'housing'], $attrs));
    }

    public function test_region_and_city_in_use_cannot_be_deleted(): void
    {
        $regionId = DB::table('regions')->insertGetId(['name_ar' => 'الرياض', 'created_at' => now(), 'updated_at' => now()]);
        $cityId = DB::table('cities')->insertGetId(['name_ar' => 'الرياض', 'region_id' => $regionId, 'created_at' => now(), 'updated_at' => now()]);
        $contract = $this->contract(['property_city_id' => $cityId]);

        $this->postJson('/api/admin/cities/'.$cityId.'/delete', [], ['Accept-Language' => 'ar'])->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن الحذف: مرتبط بطلبات');
        // المنطقة: الطلب مرتبط بمدينة داخلها.
        $this->postJson('/api/admin/regions/'.$regionId.'/delete')->assertStatus(422);

        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
        $this->assertDatabaseHas('cities', ['id' => $cityId]);
    }

    public function test_unused_city_can_still_be_deleted(): void
    {
        $cityId = DB::table('cities')->insertGetId(['name_ar' => 'غير مستخدمة', 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/admin/cities/'.$cityId.'/delete')->assertOk();
        $this->assertDatabaseMissing('cities', ['id' => $cityId]);
    }

    public function test_lookup_usage_covers_unit_and_property_types(): void
    {
        $this->contract(['unit_type_id' => 77, 'property_type_id' => 88, 'unit_usage_id' => 55]);

        $this->assertTrue(LookupUsage::contractsReference('unit_types', 77));
        $this->assertTrue(LookupUsage::contractsReference('rea_estat_types', 88));
        $this->assertTrue(LookupUsage::contractsReference('unit_usages', 55));
        $this->assertFalse(LookupUsage::contractsReference('unit_types', 78));
    }

    public function test_employee_with_financial_records_cannot_be_deleted(): void
    {
        $employee = Employee::query()->create([
            'name' => 'موظف', 'email' => 'e'.uniqid().'@t.local',
            'password' => Hash::make('x'), 'is_active' => true,
        ]);
        DB::table('contract_paid_by_employees')->insert([
            'contract_uuid' => '123123', 'employee_id' => $employee->id, 'customer_mobile' => '0550000000',
            'amount' => 249, 'is_paid' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/admin/employees/'.$employee->id.'/delete')->assertStatus(422);
        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
        $this->assertSame(1, DB::table('contract_paid_by_employees')->where('employee_id', $employee->id)->count());
    }
}
