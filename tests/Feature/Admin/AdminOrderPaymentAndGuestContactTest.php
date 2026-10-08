<?php

namespace Tests\Feature\Admin;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\Employee;
use App\Models\Setting;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * فحص: DASHBOARD-9 (المبلغ المدفوع من الدفعات الناجحة فقط) و DASHBOARD-8 (جوال واتساب الزائر في اللوحة).
 */
class AdminOrderPaymentAndGuestContactTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false, 'services.firebase.disabled' => true]);
        DB::purge('sqlite'); DB::setDefaultConnection('sqlite'); DB::reconnect('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => 'ContractStatusSeeder', '--force' => true]);
        Setting::query()->create(['whatsapp' => '966500000000']);
        Sanctum::actingAs(Employee::query()->create([
            'name' => 'مدير', 'email' => 'm'.uniqid().'@t.local', 'password' => Hash::make('x'),
            'is_active' => true, 'role' => 'admin',
        ]));
    }

    private function guestContract(): Contract
    {
        $guest = User::query()->create([
            'is_guest' => true, 'contact_mobile' => '00966551234567', 'password' => bcrypt('x'), 'is_active' => true,
        ]);

        return Contract::query()->create([
            'user_id' => $guest->id, 'contract_type' => 'commercial', 'step' => 7, 'is_completed' => 1,
            'contract_status_id' => ContractStatus::NEW_ID,
        ]);
    }

    public function test_failed_payment_is_not_reported_as_paid_amount(): void
    {
        $contract = $this->guestContract();
        DB::table('payments')->insert([
            'name' => 'p', 'contract_uuid' => $contract->uuid, 'amount' => 895, 'status' => 'failed',
            'payment_method' => 'mada', 'tran_currency' => 'SAR', 'payment_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->getJson('/api/admin/orders/'.$contract->id)->assertOk()->json('data');
        $flat = json_encode($data);

        $this->assertStringNotContainsString('"amount_payment":895', $flat);
    }

    public function test_guest_whatsapp_contact_reaches_admin_order_detail_and_list(): void
    {
        $contract = $this->guestContract();

        $detail = $this->getJson('/api/admin/orders/'.$contract->id)->assertOk()->json('data');
        $this->assertStringContainsString('551234567', json_encode($detail));

        $list = $this->getJson('/api/admin/orders?status_id='.ContractStatus::NEW_ID)->assertOk()->json();
        $this->assertStringContainsString('551234567', json_encode($list));
    }
}
