<?php

namespace Tests\Feature\BatchD;

use App\Models\Offer;
use App\Models\ReceivedContract;
use App\Models\Setting;
use App\Services\Orders\AutoAssignService;
use App\Services\Orders\OrderFlowService;

/**
 * دفعة (د) — ب13: الإسناد التلقائي بعد الدفع (بالدور / الأقل حملاً) + إعدادات اللوحة.
 */
class AutoAssignTest extends BatchDTestCase
{
    private function paidAndFlowed(): \App\Models\Contract
    {
        $contract = $this->paidContract(['contract_status_id' => 1]);
        $this->payment($contract, 249, 'pay_'.uniqid());
        app(OrderFlowService::class)->afterPayment($contract);

        return $contract->fresh();
    }

    public function test_disabled_by_default_nothing_is_assigned(): void
    {
        $this->employee('manager', false);
        $contract = $this->paidAndFlowed();

        $this->assertSame($this->statusId('under_review'), (int) $contract->contract_status_id);
        $this->assertFalse(ReceivedContract::query()->where('contract_id', $contract->id)->exists());
    }

    public function test_round_robin_assigns_in_turn_sets_status_and_notifies(): void
    {
        $a = $this->employee('manager', false);
        $b = $this->employee('customer_service', false);
        $this->employee('admin', false); // مدير النظام لا يُسند له تلقائياً
        Setting::query()->update(['auto_assign_orders' => true, 'auto_assign_strategy' => 'round_robin']);

        $eligible = app(AutoAssignService::class)->eligible()->pluck('id')->all();
        $this->assertContains($a->id, $eligible);

        $first = $this->paidAndFlowed();
        $second = $this->paidAndFlowed();
        $third = $this->paidAndFlowed();

        $assigned = fn ($c) => (int) ReceivedContract::query()->where('contract_id', $c->id)->value('employee_id');
        $this->assertSame($this->statusId('received_by_employee'), (int) $first->contract_status_id);
        $this->assertNotSame($assigned($first), $assigned($second));
        $this->assertSame($assigned($first), $assigned($third));
        $this->assertTrue(Offer::query()->where('contract_id', $first->id)->where('kind', 'assigned')->exists());
        $this->assertContains('assigned', \App\Models\ContractActivity::query()->where('contract_id', $first->id)->pluck('action')->all());
    }

    public function test_least_load_and_settings_api(): void
    {
        $admin = $this->employee('admin');
        $busy = $this->employee('manager', false);
        $free = $this->employee('manager', false);
        foreach ([1, 2] as $_) {
            $c = $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee')]);
            ReceivedContract::query()->create(['contract_id' => $c->id, 'employee_id' => $busy->id, 'status' => 'finish', 'date_of_received' => now()->toDateString()]);
        }

        $this->postJson('/api/admin/settings', [
            'auto_assign_orders' => true,
            'auto_assign_strategy' => 'least_load',
            'auto_assign_employee_ids' => [$busy->id, $free->id],
        ])->assertOk();

        $section = $this->getJson('/api/admin/settings')->assertOk()->json('data.auto_assign');
        $this->assertTrue($section['enabled']);
        $this->assertSame('least_load', $section['strategy']);
        $this->assertEqualsCanonicalizing([$busy->id, $free->id], array_column($section['eligible_employees'], 'id'));

        $contract = $this->paidAndFlowed();
        $this->assertSame($free->id, (int) ReceivedContract::query()->where('contract_id', $contract->id)->value('employee_id'));

        $this->postJson('/api/admin/settings', ['auto_assign_strategy' => 'random'])->assertStatus(422);
    }
}
