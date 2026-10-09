<?php

namespace Tests\Feature\BatchD;

use App\Models\Refund;
use App\Models\UnitsReal;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * متابعة دفعة (د): refund{status,amount,refunded_at} + refunded_amount في حمولات العميل،
 * وعدد العدادات وسعر العداد في /financial و /payment، و checks في /status.
 */
class CustomerRefundAndMeterPayloadTest extends BatchDTestCase
{
    public function test_customer_payloads_carry_refund_summary(): void
    {
        $user = $this->customer('0551234567');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee')], $user);
        $payment = $this->payment($contract, 349);
        Sanctum::actingAs($user);

        $this->getJson('/api/v2/contracts/'.$contract->id)->assertOk()->assertJsonPath('data.refund.status', 'none');

        Refund::query()->create(['payment_id' => $payment->id, 'contract_id' => $contract->id, 'contract_uuid' => (string) $contract->uuid, 'amount' => 100, 'status' => 'succeeded']);
        $this->getJson('/api/v2/contracts/'.$contract->id)->assertOk()
            ->assertJsonPath('data.refund.status', 'partial')
            ->assertJsonPath('data.refunded_amount', 100);

        Refund::query()->create(['payment_id' => $payment->id, 'contract_id' => $contract->id, 'contract_uuid' => (string) $contract->uuid, 'amount' => 249, 'status' => 'succeeded']);
        $track = $this->postJson('/api/v2/contract/track', ['order' => (string) $contract->uuid, 'mobile' => '0551234567'])->assertOk()->json('data');
        $this->assertSame('full', $track['refund']['status']);
        $this->assertEquals(349, $track['refund']['amount']);
        $this->assertNotNull($track['refund']['refunded_at']);

        $fin = $this->getJson('/api/v2/financial/'.$contract->uuid)->assertOk()->json('data');
        $this->assertSame('full', $fin['refund']['status']);
        $this->assertEquals(349, $fin['refunded_amount']);

        $pay = $this->getJson('/api/v2/payment/'.$contract->uuid)->json();
        $this->assertSame('full', data_get($pay, 'refund.status') ?? data_get($pay, 'data.refund.status'));

        $invoice = app(\App\Services\ContractInvoiceService::class)->forContract($contract->fresh());
        $this->assertSame('full', $invoice['refund']['status']);
    }

    public function test_financial_has_meter_counts_and_unit_fees(): void
    {
        DB::table('settings')->update(['electricity_meter_fee_housing_tenant' => 15, 'water_meter_fee_housing_tenant' => 15]);
        \App\Support\DocFee::flushSettingsCache();
        $user = $this->customer();
        $contract = $this->contract([], $user);
        foreach ([1, 2] as $n) {
            $unit = UnitsReal::query()->create(['user_id' => $user->id, 'unit_number' => (string) $n, 'electricity_meter_ownership' => 'tenant']);
            DB::table('contract_units')->insert(['contract_id' => $contract->id, 'real_unit_id' => $unit->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        Sanctum::actingAs($user);

        $fin = $this->getJson('/api/v2/financial/'.$contract->uuid)->assertOk()->json('data');
        $this->assertSame(2, $fin['price_details']['electricity_meter_count']);
        $this->assertEquals(15, $fin['price_details']['electricity_meter_unit_fee']);
        $this->assertEquals(30, $fin['price_details']['electricity_meter_fee']);
        $this->assertSame(0, $fin['price_details']['water_meter_count']);
        $this->assertSame(2, $fin['electricity_meter_count']);
    }

    public function test_status_has_checks_map(): void
    {
        $this->getJson('/api/v2/status')->assertOk()
            ->assertJsonPath('checks.api', 'ok')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonStructure(['status', 'checks' => ['api', 'db', 'database', 'scheduler', 'gateway'], 'components', 'checked_at']);
    }
}
