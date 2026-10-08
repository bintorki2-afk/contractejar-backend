<?php

namespace Tests\Feature\BatchD;

use App\Models\Contract;
use App\Models\Setting;
use App\Models\UnitsReal;
use App\Services\ContractInvoiceService;
use App\Support\ContractPricing;
use App\Support\DocFee;
use App\Support\MeterFees;
use Illuminate\Support\Facades\DB;

/**
 * دفعة (د) — ب1: رسوم نقل العداد لكل عداد (لكل وحدة)، وب7: أشهر العداد المشترك = مدة العقد.
 */
class MeterFeesPerMeterTest extends BatchDTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->first()->forceFill([
            'electricity_meter_fee_housing_tenant' => 15,
            'water_meter_fee_housing_tenant' => 15,
            'electricity_meter_fee_commercial_tenant' => 25,
            'water_meter_fee_commercial_tenant' => 25,
        ])->save();
        DocFee::flushSettingsCache();
    }

    /** @param list<array<string, mixed>> $units */
    private function withUnits(Contract $contract, array $units): Contract
    {
        foreach ($units as $attrs) {
            $unit = UnitsReal::query()->create(array_merge(['user_id' => $contract->user_id], $attrs));
            DB::table('contract_units')->insert([
                'contract_id' => $contract->id, 'real_unit_id' => $unit->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $contract->fresh();
    }

    public function test_two_units_with_tenant_electricity_meter_cost_two_fees(): void
    {
        $contract = $this->withUnits($this->contract(), [
            ['electricity_meter' => 1, 'electricity_meter_number' => '1', 'electricity_meter_ownership' => 'tenant'],
            ['electricity_meter' => 1, 'electricity_meter_number' => '2', 'electricity_meter_ownership' => 'tenant'],
        ]);

        $fees = MeterFees::forContract($contract);
        $this->assertSame(30.0, $fees['electricity_meter_fee']);
        $this->assertSame(2, $fees['electricity_meter_count']);
        $this->assertSame(0.0, $fees['water_meter_fee']);
        $this->assertSame(30.0, $fees['meter_fees_total']);
    }

    public function test_commercial_two_units_two_meters_each_is_four_times_25(): void
    {
        $contract = $this->withUnits($this->contract(['contract_type' => 'commercial']), [
            ['electricity_meter_ownership' => 'tenant', 'water_meter_ownership' => 'tenant'],
            ['electricity_meter_ownership' => 'tenant', 'water_meter_ownership' => 'tenant'],
        ]);

        $fees = MeterFees::forContract($contract);
        $this->assertSame(100.0, $fees['meter_fees_total']);
        $this->assertSame(50.0, $fees['electricity_meter_fee']);
        $this->assertSame(50.0, $fees['water_meter_fee']);

        // يدخل في السعر الإجمالي والفاتورة (الكمية = عدد العدادات).
        $pricing = ContractPricing::for($contract);
        $this->assertSame(100.0, (float) $pricing['meter_fees_total']);

        $invoice = app(ContractInvoiceService::class)->forContract($contract);
        $lines = collect($invoice['items'])->keyBy('key');
        $this->assertSame(2, $lines['electricity_meter']['quantity']);
        $this->assertSame(50.0, (float) $lines['electricity_meter']['amount']);
    }

    public function test_owner_and_mixed_units_only_count_tenant_meters(): void
    {
        $contract = $this->withUnits($this->contract(), [
            ['electricity_meter_ownership' => 'tenant', 'water_meter_ownership' => 'owner'],
            ['electricity_meter_ownership' => 'owner', 'water_meter_ownership' => 'shared', 'water_shared_monthly_fee' => 50],
        ]);

        $fees = MeterFees::forContract($contract);
        $this->assertSame(15.0, $fees['electricity_meter_fee']);
        $this->assertSame(0.0, $fees['water_meter_fee']);
    }

    public function test_contract_level_ownership_counts_one_meter_without_units(): void
    {
        $contract = $this->contract(['electricity_meter_ownership' => 'tenant']);

        $this->assertSame(15.0, MeterFees::forContract($contract)['electricity_meter_fee']);
    }

    public function test_pricing_endpoint_keeps_per_meter_label(): void
    {
        $this->getJson('/api/v2/pricing')->assertOk()
            ->assertJsonPath('data.meter_transfer_fee.per_meter', true)
            ->assertJsonPath('data.meter_transfer_fee.housing.electricity', 15);
        $this->assertStringContainsString('لكل عداد', (string) $this->getJson('/api/v2/pricing')->json('data.meter_transfer_fee.label'));
    }
}
