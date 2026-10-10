<?php

namespace Tests\Feature\BatchF;

use App\Models\ContractCharge;
use App\Models\Payment;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\BatchE\BatchETestCase;

/**
 * دفعة (و) — اختبارات إصلاح باقي عيوب QA-100 (جزء الخادم).
 */
class RemainingQaFixesTest extends BatchETestCase
{
    private function paidExtraFee(\App\Models\Contract $contract, float $amount = 450.0): ContractCharge
    {
        $charge = ContractCharge::query()->create([
            'contract_id' => $contract->id, 'kind' => ContractCharge::KIND_EXTRA_FEE, 'amount' => $amount,
            'message' => 'رسوم وحدة إضافية', 'internal_reason' => 'سبب داخلي سري', 'status' => ContractCharge::STATUS_PAID,
            'paid_at' => now(),
        ]);
        $p = $this->payment($contract, $amount, 'pay_chg_'.$charge->id);
        $p->forceFill(['charge_id' => $charge->id, 'kind' => Payment::KIND_EXTRA_FEE, 'contract_uuid' => Payment::chargeKey((string) $contract->uuid, $charge->id), 'contract_id' => $contract->id])->save();
        $charge->forceFill(['payment_id' => $p->id])->save();

        return $charge;
    }

    public function test_app14_internal_reason_hidden_from_customer_but_visible_to_admin(): void
    {
        $user = $this->customer('0551112233');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')], $user);
        $this->payFull($contract);
        $this->paidExtraFee($contract);

        Sanctum::actingAs($user);
        $json = $this->getJson('/api/v2/contracts/'.$contract->id)->assertOk()->json('data');
        $this->assertNull($json['payment_details']['charges'][0]['internal_reason']);
        $this->assertNull($json['charges'][0]['internal_reason'] ?? null);
        $this->assertStringNotContainsString('سبب داخلي سري', json_encode($json, JSON_UNESCAPED_UNICODE));

        $this->employee('admin');
        $admin = $this->getJson('/api/admin/orders/'.$contract->id.'/payment-state')->assertOk()->json('data');
        $this->assertStringContainsString('سبب داخلي سري', json_encode($admin, JSON_UNESCAPED_UNICODE));
    }

    public function test_orders_com3_invoice_subtotal_includes_paid_charges_and_app8_financial_fields(): void
    {
        $user = $this->customer('0551112234');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review'), 'annual_rent_amount_for_the_unit' => 36000], $user);
        $this->payFull($contract);
        $this->paidExtraFee($contract, 450);

        $invoice = app(\App\Services\ContractInvoiceService::class)->forContract($contract->fresh());
        $sum = array_sum(array_map(static fn ($i) => $i['amount'] > 0 ? $i['amount'] : 0, $invoice['items']));
        $this->assertEqualsWithDelta($sum, $invoice['subtotal'], 0.01);
        $this->assertEqualsWithDelta($invoice['original_subtotal'] + 450, $invoice['subtotal'], 0.01);
        $this->assertSame(0.0, (float) $invoice['outstanding']);
        $this->assertNotSame(' ', $invoice['customer_name']);

        Sanctum::actingAs($user);
        $json = $this->getJson('/api/v2/contracts/'.$contract->id)->assertOk()->json('data');
        $this->assertSame(36000, (int) $json['annual_rent_amount_for_the_unit']);
        $this->assertSame('سنة', $json['contract_period']);
        $this->assertNotNull($json['total_price']);
        $this->assertArrayHasKey('invoice_url', $json);
    }

    public function test_orders_com4_legacy_amount_payment_is_net_after_charges(): void
    {
        $this->employee('admin');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')]);
        $this->payFull($contract);
        $this->paidExtraFee($contract, 450);

        $row = $this->getJson('/api/admin/orders/'.$contract->id)->assertOk()->json('data');
        $net = $row['payment_state']['net_total'] ?? null;
        $this->assertNotNull($net);
        $this->assertEqualsWithDelta((float) $net, (float) $row['amount_payment'], 0.01);
        $this->assertTrue($row['is_paid']);
    }

    public function test_dash19_bank_transfer_message_requires_record_transfer(): void
    {
        $contract = $this->contract();
        $this->limitedEmployee(['all_requests.view']);
        $this->getJson('/api/admin/orders/'.$contract->id.'/bank-transfer-message')->assertStatus(403);

        $this->limitedEmployee(['all_requests.view', 'payments.record_transfer']);
        $this->getJson('/api/admin/orders/'.$contract->id.'/bank-transfer-message')->assertOk();
    }

    public function test_dash21_export_hides_money_columns_without_payments_view(): void
    {
        $this->paidContract();
        $this->limitedEmployee(['all_requests.view']);
        $csv = $this->get('/api/admin/orders/export?format=csv')->assertOk()->getContent();
        $this->assertStringNotContainsString('الصافي', $csv);
        $this->assertStringContainsString('رقم الطلب', $csv);

        $this->limitedEmployee(['all_requests.view', 'payments.view']);
        $csv = $this->get('/api/admin/orders/export?format=csv')->assertOk()->getContent();
        $this->assertStringContainsString('الصافي', $csv);
    }
}
