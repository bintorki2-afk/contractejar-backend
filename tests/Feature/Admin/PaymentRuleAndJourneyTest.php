<?php

namespace Tests\Feature\Admin;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\ContractStatusHistory;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Setting;
use App\Modules\Users\Models\User;
use App\Services\MoyasarPaymentService;
use App\Support\ContractJourney;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * دفعة (هـ) — E2/E3: قاعدة «الدفع قبل التوثيق» على الخادم + رحلة الطلب (3 خطوات).
 * (حلّت محل اختبار قاعدة المسودة من دفعة الإصلاحات ب.)
 */
class PaymentRuleAndJourneyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
            'services.firebase.disabled' => true,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => 'RoleSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'PermissionSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'ContractStatusSeeder', '--force' => true]);
        Setting::query()->create(['whatsapp' => '966500000000']);
    }

    private function employee(string $roleName): Employee
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
        Sanctum::actingAs($employee);

        return $employee;
    }

    private function paidContract(bool $withPayment = true): Contract
    {
        $user = User::query()->create([
            'name' => 'عميل', 'mobile' => '0551234567', 'email' => 'c'.uniqid().'@test.local',
            'password' => bcrypt('x'), 'is_active' => true,
        ]);

        $contract = Contract::query()->create([
            'user_id' => $user->id, 'contract_type' => 'housing', 'instrument_type' => 'electronic',
            'duration_preset' => '1_year', 'total_months' => 12, 'step' => 7, 'is_completed' => 1,
            'tenant_mobile' => '0551234567', 'contract_status_id' => ContractStatus::NEW_ID,
        ]);
        if ($withPayment) {
            \App\Models\Payment::query()->create([
                'name' => 'pay', 'amount' => \App\Support\ContractPricing::total($contract), 'payment_date' => now()->toDateString(),
                'contract_uuid' => (string) $contract->uuid, 'tran_currency' => 'SAR', 'payment_method' => 'creditcard', 'status' => 'success',
            ]);
        }

        return $contract;
    }

    public function test_notarization_is_rejected_before_payment(): void
    {
        $this->employee('manager');
        $contract = $this->paidContract(withPayment: false);
        $contract->forceFill(['is_completed' => 0])->save();

        $response = $this->postJson('/api/admin/orders/'.$contract->id.'/status', [
            'status_id' => ContractStatus::EJAR_AUTHENTICATION_ID,
            'deed_number' => '123456',
            'deed_type' => 'electronic',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'payment_required')
            ->assertJsonPath('errors.contract_status_id.0', \App\Services\Payments\ContractPaymentState::BLOCK_MESSAGES['payment_required']);

        $this->assertSame(ContractStatus::NEW_ID, (int) $contract->fresh()->contract_status_id);

        // «مكتمل» تخضع لنفس القاعدة.
        $completedId = (int) ContractStatus::query()->where('name', 'مكتمل')->value('id');
        $this->postJson('/api/admin/orders/'.$contract->id.'/status', ['status_id' => $completedId])
            ->assertStatus(422)->assertJsonPath('code', 'payment_required');

        // غير مدير النظام لا يستطيع التجاوز حتى مع force=1.
        $this->postJson('/api/admin/orders/'.$contract->id.'/status', [
            'status_id' => ContractStatus::EJAR_AUTHENTICATION_ID,
            'deed_number' => '123456',
            'deed_type' => 'electronic',
            'force' => 1,
        ])->assertStatus(422);
    }

    public function test_notarization_allowed_after_payment_and_journey_reflects_three_steps(): void
    {
        $this->employee('manager');
        $contract = $this->paidContract();

        // لا مرحلة مسودة بعد الآن ⇒ 410.
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/draft_sent', ['ejar_contract_draft_number' => 'D1', 'contact_number_mode' => 'same'])
            ->assertStatus(410);

        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/received')->assertOk();

        $this->postJson('/api/admin/orders/'.$contract->id.'/status', [
            'status_id' => ContractStatus::EJAR_AUTHENTICATION_ID,
            'deed_number' => '123456',
            'deed_type' => 'electronic',
        ])->assertOk();

        $this->assertSame(ContractStatus::EJAR_AUTHENTICATION_ID, (int) $contract->fresh()->contract_status_id);

        $journey = ContractJourney::for($contract->fresh(['contractStatus', 'statusHistories']));
        $this->assertCount(3, $journey);
        $this->assertSame(['under_review', 'received_by_employee', 'ejar_authenticated'], array_column($journey, 'key'));
        $this->assertSame([true, true, true], array_column($journey, 'done'));
        $this->assertSame([false, false, false], array_column($journey, 'current'));
        $this->assertNotNull($journey[1]['at']);
        $this->assertSame('موظف manager', $journey[1]['by']);
        $this->assertNull(ContractJourney::sideState($contract->fresh()));
    }

    public function test_super_admin_can_force_and_it_is_recorded(): void
    {
        $admin = $this->employee('admin');
        $contract = $this->paidContract(withPayment: false);
        $contract->forceFill(['is_completed' => 0])->save();

        $this->postJson('/api/admin/orders/'.$contract->id.'/status', [
            'status_id' => ContractStatus::EJAR_AUTHENTICATION_ID,
            'deed_number' => '123456',
            'deed_type' => 'electronic',
            'force' => 1,
        ])->assertOk();

        $row = ContractStatusHistory::query()
            ->where('contract_id', $contract->id)->where('status', 'ejar_authenticated')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) ($row->meta['payment_rule_forced'] ?? false));
        $this->assertSame($admin->id, (int) $row->meta['payment_rule_forced_by']);
    }

    /** WEBSITE-2: طلب مدفوع وحالته الإدارية ما زالت «جديد» يظهر للعميل «تم الدفع». */
    public function test_paid_order_with_status_new_shows_paid_to_customer(): void
    {
        $contract = $this->paidContract();
        Sanctum::actingAs($contract->user, ['*']);

        $detail = $this->getJson('/api/v2/contracts/'.$contract->id)->assertOk()->json('data');
        $this->assertSame('paid', $detail['status']);
        $this->assertSame('تم الدفع', $detail['status_label']);
        $this->assertSame('paid', $detail['payment_state']['status']);

        $list = $this->getJson('/api/v2/contracts')->assertOk()->json();
        $this->assertStringContainsString('"status":"paid"', json_encode($list, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('"status":"new"', json_encode($list, JSON_UNESCAPED_UNICODE));

        // غير مدفوع ⇒ يبقى «جديد».
        $contract->forceFill(['is_completed' => 0])->save();
        $this->assertSame('new', $this->getJson('/api/v2/contracts/'.$contract->id)->json('data.status'));
    }

    public function test_customer_contract_and_track_expose_three_step_journey_and_side_state(): void
    {
        $contract = $this->paidContract();
        $user = $contract->user;

        Sanctum::actingAs($user, ['*']);
        $data = $this->getJson('/api/v2/contracts/'.$contract->id)->assertOk()->json('data');
        $journey = $data['journey'];

        $this->assertCount(3, $journey);
        $this->assertTrue($journey[0]['done']);    // قيد المراجعة (الدفع)
        $this->assertFalse($journey[1]['done']);
        $this->assertTrue($journey[1]['current']); // مستلم من الموظف
        $this->assertSame('مستلم من الموظف', $journey[1]['label']);
        $this->assertSame('تم التوثيق', $journey[2]['label']);
        $this->assertNull($data['journey_side_state']);
        $this->assertSame(ContractJourney::RULE_SENTENCE, $data['journey_sentence']);
        $this->assertStringNotContainsString('مسودة', json_encode($journey, JSON_UNESCAPED_UNICODE));

        $this->mock(MoyasarPaymentService::class, fn ($mock) => $mock->shouldReceive('isPaymentConfirmed')->andReturn(true));
        $track = $this->postJson('/api/v2/contract/track', ['order' => (string) $contract->uuid, 'mobile' => '0551234567'])
            ->assertOk()
            ->json('data');
        $this->assertCount(3, $track['journey']);
        $this->assertSame(ContractJourney::RULE_SENTENCE, $track['journey_sentence']);
        $this->assertSame('paid', $track['payment_state']['status']);

        // حالة جانبية: ملغي.
        $cancelledId = (int) ContractStatus::query()->where('name', 'ملغى')->value('id');
        $contract->forceFill(['contract_status_id' => $cancelledId])->save();
        app(\App\Services\ContractStatusHistoryService::class)->record($contract->fresh(['contractStatus']), ['source' => 'admin']);
        $side = $this->getJson('/api/v2/contracts/'.$contract->id)->assertOk()->json('data.journey_side_state');
        $this->assertSame('cancelled', $side['key']);
        $this->assertSame('ملغي', $side['label']);

        // مسودة غير مُرسلة (step 5): الخطوة الأولى حالية.
        $draft = Contract::query()->create([
            'user_id' => $user->id, 'contract_type' => 'housing', 'step' => 5,
        ]);
        $template = ContractJourney::for($draft->fresh());
        $this->assertFalse($template[0]['done']);
        $this->assertTrue($template[0]['current']);
    }
}
