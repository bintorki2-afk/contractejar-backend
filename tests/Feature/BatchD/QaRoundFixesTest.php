<?php

namespace Tests\Feature\BatchD;

use App\Models\Contract;
use App\Models\NotificationDispatch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * متابعة دفعة (د) — جولة QA: الشروط الإضافية من التطبيق، الضمان/الغرامة من صفات المستأجر، قناة الطلب،
 * push_result=disabled، حقول المدة والعدادات في التفاصيل، الطلب المدفوع في السلة للعميل، الإيراد الموحّد، رسائل تغيير المؤجر.
 */
class QaRoundFixesTest extends BatchDTestCase
{
    private function references(): void
    {
        foreach (['PaymentTypeSeeder', 'ContractPeriodSeeder'] as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }
    }

    private function step6(Contract $contract, array $extra): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v2/contract/step6', array_merge([
            'id' => $contract->id, 'contract_starting_date_day' => 1, 'contract_starting_date_month' => 11, 'contract_starting_date_year' => 2026,
            'type_contract_starting_date' => 'gregorian', 'contract_term_in_years' => (int) DB::table('contract_periods')->where('contract_type', 'housing')->value('id'),
            'annual_rent_amount_for_the_unit' => 30000, 'payment_type_id' => (int) DB::table('payment_types')->value('id'),
        ], $extra));
    }

    public function test_app_step6_keeps_conditions_text_and_derives_amounts_from_tenant_roles(): void
    {
        $this->references();
        $user = $this->customer();
        $contract = $this->contract(['step' => 6, 'is_completed' => 0], $user);
        Sanctum::actingAs($user);

        // شكل التطبيق: conditions=false + additional_terms=true + other_conditions نص، والمبالغ كقيم صفات (5 غرامة، 6 ضمان).
        $this->step6($contract, [
            'conditions' => false, 'additional_terms' => true, 'other_conditions' => 'لا يسمح بالتدخين',
            'tenant_roles' => true, 'tenant_role_ids' => [5, 6], 'tenant_role_values' => ['5' => '100', '6' => '2000'],
        ])->assertOk();

        $fresh = $contract->fresh();
        $this->assertSame('لا يسمح بالتدخين', $fresh->other_conditions);
        $this->assertSame(['لا يسمح بالتدخين'], $fresh->other_conditions_list);
        $this->assertEquals(2000, $fresh->Guarantee_amount);
        $this->assertEquals(100, $fresh->daily_fine);

        // الحقل المخصّص له الأولوية على قيمة الصفة.
        $this->step6($contract, ['conditions' => false, 'tenant_roles' => true, 'tenant_role_ids' => [6], 'tenant_role_values' => ['6' => '2000'], 'Guarantee_amount' => 3000])->assertOk();
        $this->assertEquals(3000, $contract->fresh()->Guarantee_amount);
    }

    public function test_app_or_web_follows_the_real_client(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);
        $web = $this->withHeaders(['X-Client' => 'website'])->postJson('/api/v2/contract/start', ['contract_type' => 'housing'])->json('data.contract_id');
        $app = $this->withHeaders(['X-Client' => 'app'])->postJson('/api/v2/contract/start', ['contract_type' => 'commercial'])->json('data.contract_id');
        $this->assertSame('web', Contract::query()->find($web)->app_or_web);
        $this->assertSame('app', Contract::query()->find($app)->app_or_web);

        // step1 لم يعد يستبدلها بـ app.
        $this->withHeaders(['X-Client' => 'website'])->postJson('/api/v2/contract/step1', ['id' => $web, 'instrument_type' => 'electronic', 'instrument_number' => '1'])->assertOk();
        $this->assertSame('web', Contract::query()->find($web)->app_or_web);

        // جلسة زائر بلا ترويسة ⇒ web.
        $guest = \App\Modules\Users\Models\User::query()->create(['is_guest' => true, 'is_active' => true]);
        Sanctum::actingAs($guest);
        $id = $this->withHeaders(['X-Client' => ''])->postJson('/api/v2/contract/start', ['contract_type' => 'housing'])->json('data.contract_id');
        $this->assertSame('web', Contract::query()->find($id)->app_or_web);
    }

    public function test_broadcast_without_firebase_is_disabled_not_sent(): void
    {
        $this->employee('admin');
        $this->customer('0551000001');
        config(['services.firebase.disabled' => true]);

        $this->postJson('/api/admin/notifications/all-users', ['title' => 'عرض', 'body' => 'نص', 'kind' => 'offer'])->assertOk();
        $this->assertSame('disabled', NotificationDispatch::query()->whereNull('user_id')->latest('id')->value('push_result'));
    }

    public function test_admin_detail_has_months_shared_meters_and_start_dates(): void
    {
        $this->references();
        $this->employee('manager');
        $period = (int) DB::table('contract_periods')->where('contract_type', 'housing')->where('months', 24)->value('id');
        $contract = $this->paidContract(['duration_preset' => null, 'total_months' => null, 'contract_term_in_years' => $period,
            'contract_starting_date' => '01-05-1448', 'type_contract_starting_date' => 'hijri']);
        $unit = \App\Models\UnitsReal::query()->create(['user_id' => $contract->user_id, 'unit_number' => '1', 'water_meter_ownership' => 'shared', 'water_shared_monthly_fee' => 40]);
        DB::table('contract_units')->insert(['contract_id' => $contract->id, 'real_unit_id' => $unit->id, 'created_at' => now(), 'updated_at' => now()]);

        $d = $this->getJson('/api/admin/orders/'.$contract->id)->assertOk()->json('data');
        $this->assertSame(24, $d['contract_months']);
        $this->assertSame(24, $d['contract_term_in_years']['months']);
        $this->assertSame(24, $d['shared_meters']['water']['months']);
        $this->assertEquals(960, $d['shared_meters']['water']['total']);
        $this->assertSame('2026-10-12', $d['contract_starting_date_gregorian']);
        $this->assertSame('01-05-1448', $d['contract_starting_date_hijri']);
    }

    public function test_trashed_paid_order_stays_visible_to_customer_as_cancelled(): void
    {
        $this->employee('admin');
        $user = $this->customer();
        $paid = $this->paidContract([], $user);
        $this->payment($paid, 249);
        $draft = $this->contract([], $user);
        $this->deleteJson('/api/admin/orders/'.$paid->id.'?force=1')->assertOk();
        $this->deleteJson('/api/admin/orders/'.$draft->id)->assertOk();

        Sanctum::actingAs($user);
        $this->getJson('/api/v2/contracts/'.$paid->id)->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.status_label', 'ملغي');
        $this->getJson('/api/v2/contracts/'.$draft->id)->assertNotFound();
        $this->getJson('/api/v2/invoices/'.$paid->id)->assertOk();
    }

    public function test_overview_and_performance_revenue_match(): void
    {
        $this->employee('admin');
        $c = $this->paidContract();
        $this->payment($c, 500);
        $old = $this->paidContract();
        $p = $this->payment($old, 300, 'p_old');
        DB::table('payments')->where('id', $p->id)->update(['payment_date' => now()->subDays(40)->toDateString()]);

        $perf = $this->getJson('/api/admin/reports/performance?period=today')->assertOk()->json('data.kpis.revenue');
        $overview = collect($this->getJson('/api/admin/reports/overview?range=today')->assertOk()->json('data.cards'))->firstWhere('key', 'revenue');
        $this->assertEquals(500, $perf);
        $this->assertEquals($perf, $overview['value']);
    }

    public function test_lessor_change_validation_messages_are_arabic(): void
    {
        Sanctum::actingAs($this->customer());
        $errors = $this->postJson('/api/v2/lessor-change', ['new_owner_dob_day' => 40, 'new_owner_dob_type' => 'x', 'notes' => str_repeat('a', 2100), 'platform' => 'tv'])
            ->assertStatus(422)->json('errors');
        foreach (collect($errors)->flatten() as $message) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $message, $message);
        }
    }
}
