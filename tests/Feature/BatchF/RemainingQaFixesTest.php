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

    private function property(\App\Models\User $user, array $attrs = []): \App\Models\RealEstate
    {
        $re = new \App\Models\RealEstate();
        $re->forceFill(array_merge(['user_id' => $user->id, 'name_real_estate' => 'عقار اختبار', 'step' => 3], $attrs))->save();

        return $re;
    }

    private function unitFor(\App\Models\RealEstate $re, \App\Models\User $user): \App\Models\UnitsReal
    {
        $u = new \App\Models\UnitsReal();
        $u->forceFill(['user_id' => $user->id, 'unit_number' => (string) random_int(1, 9999), 'real_estates_units_id' => $re->id])->save();

        return $u;
    }

    public function test_props1_property_with_linked_unit_cannot_be_deleted_and_props13_count_follows_unit_delete(): void
    {
        $user = $this->customer('0551112240');
        $re = $this->property($user);
        $linked = $this->unitFor($re, $user);
        $free1 = $this->unitFor($re, $user);
        $free2 = $this->unitFor($re, $user);
        $re->forceFill(['number_of_units_in_realestate' => '3'])->save();
        $this->contract(['real_id' => $re->id, 'real_units_id' => $linked->id, 'is_real' => 1], $user);

        Sanctum::actingAs($user);
        $this->deleteJson('/api/v2/realstate/delete/'.$re->id)->assertStatus(422);
        $this->assertNotNull(\App\Models\RealEstate::query()->find($re->id));

        $this->deleteJson('/api/v2/unit/delete/'.$free1->id)->assertOk();
        $this->assertSame('2', (string) $re->fresh()->number_of_units_in_realestate);
    }

    public function test_props7_9_admin_real_estate_exposes_attachments_and_gregorian_dob(): void
    {
        $user = $this->customer('0551112241');
        $re = $this->property($user, [
            'image_instrument' => 'real-estates/deed.png', 'dob_hijri' => '12-05-1985', 'type_dob_property_owner' => 'gregorian',
            'instrument_type' => 'sale_agreement', 'mobile' => '551000901',
        ]);
        $this->employee('admin');
        $d = $this->getJson('/api/admin/real-estates/'.$re->id)->assertOk()->json('data');
        $this->assertNotNull($d['image_instrument']);
        $this->assertSame('image_instrument', $d['attachments'][0]['key']);
        $this->assertSame('12-05-1985', $d['DOB']);
        $this->assertNull($d['dob_hijri']);
        $this->assertSame('gregorian', $d['type_dob_property_owner']);
        $this->assertSame('ورقة مبايعة مختومة من مكتب عقاري', $d['instrument_type_label']);
        $this->assertSame('966551000901', $d['mobile_international']);
    }

    public function test_props6_coordinates_from_map_url_and_placeholder_dropped(): void
    {
        $this->assertSame([21.3891, 39.8579], \App\Support\MapUrlCoordinates::fromUrl('https://maps.google.com/?q=21.3891,39.8579'));
        $this->assertSame([21.5, 39.2], \App\Support\MapUrlCoordinates::fromUrl('https://www.google.com/maps/place/x/@21.5,39.2,15z'));
        $this->assertNull(\App\Support\MapUrlCoordinates::fromUrl('https://maps.app.goo.gl/abc'));
        $this->assertTrue(\App\Support\MapUrlCoordinates::isPlaceholder('24.7136', '46.6753'));
    }

    public function test_props18_lessor_change_rejects_invalid_id_and_dob(): void
    {
        Sanctum::actingAs($this->customer('0551112242'));
        $base = [
            'old_deed_image' => $this->fakePng('old.png'), 'new_deed_image' => $this->fakePng('new.png'),
            'new_owner_id_number' => '1098765432', 'new_owner_dob_day' => 10, 'new_owner_dob_month' => 5,
            'new_owner_dob_year' => 1985, 'new_owner_dob_type' => 'gregorian', 'acknowledged' => 1,
        ];
        $this->post('/api/v2/lessor-change', array_merge($base, ['new_owner_id_number' => '0000000000']), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'رقم الهوية غير صحيح — يبدأ بـ1 (مواطن) أو 2 (مقيم) أو 7 (منشأة).');
        $base['old_deed_image'] = $this->fakePng('old.png');
        $base['new_deed_image'] = $this->fakePng('new.png');
        $this->post('/api/v2/lessor-change', array_merge($base, ['new_owner_dob_day' => 29, 'new_owner_dob_month' => 2, 'new_owner_dob_year' => 1301]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'تاريخ ميلاد المالك الجديد غير صحيح.');
    }

    public function test_orders_com5_instrument_labels_match_customer_choice(): void
    {
        $this->assertSame('صك ملكية إلكتروني من السجل العقاري', \App\Models\Contract::instrumentTypeLabel('electronic_tax_register', 'ar'));
        $this->assertSame('حجة استحكام', \App\Models\Contract::instrumentTypeLabel('strong_argument', 'ar'));
        $this->assertSame('ورقة مبايعة مختومة من مكتب عقاري', \App\Models\Contract::instrumentTypeLabel('sale_agreement', 'ar'));
    }
}
