<?php

namespace Tests\Feature\Admin;

use App\Models\Employee;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** فحص (DASHBOARD-10): نسبة الكوبون ≤ 100% والمبلغ الثابت ≥ 0، ورسائل عربية. */
class CouponValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false]);
        DB::purge('sqlite'); DB::setDefaultConnection('sqlite'); DB::reconnect('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Sanctum::actingAs(Employee::query()->create([
            'name' => 'مدير', 'email' => 'c'.uniqid().'@t.local', 'password' => Hash::make('x'),
            'is_active' => true, 'role' => 'admin',
        ]));
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'ترحيب', 'code_coupon' => 'C'.uniqid(), 'type_coupon' => 'ratio', 'value_coupon' => 10,
            'date_start' => now()->toDateString(), 'date_end' => now()->addDay()->toDateString(),
            'usage' => 10, 'usage_of_user' => 1,
        ], $over);
    }

    public function test_ratio_above_100_is_rejected_and_valid_values_pass(): void
    {
        $h = ['Accept-Language' => 'ar'];
        $this->postJson('/api/admin/coupons', $this->payload(['value_coupon' => 150]), $h)->assertStatus(422);
        $this->postJson('/api/admin/coupons', $this->payload(['type_coupon' => 'value', 'value_coupon' => -1]), $h)->assertStatus(422);
        $this->postJson('/api/admin/coupons', $this->payload(['value_coupon' => 100]), $h)->assertSuccessful();
        $this->postJson('/api/admin/coupons', $this->payload(['type_coupon' => 'value', 'value_coupon' => 150]), $h)->assertSuccessful();

        $id = DB::table('coupons')->where('type_coupon', 'ratio')->value('id');
        // تعديل القيمة وحدها لنسبة > 100 على كوبون نسبة → 422.
        $this->postJson('/api/admin/coupons/'.$id, ['value_coupon' => 120], $h)->assertStatus(422);
    }
}
