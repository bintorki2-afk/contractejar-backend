<?php

namespace Tests\Feature\BatchE;

use App\Models\ContractActivity;
use App\Models\ContractCharge;
use App\Models\EmployeeNotification;
use App\Models\NotificationDispatch;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * دفعة (هـ) — 2.3 / E5: الرسوم الإضافية وفرق السعر: إنشاء → رابط Moyasar → webhook → انعكاس في كل مكان.
 */
class ChargesLifecycleTest extends BatchETestCase
{
    public function test_extra_fee_requires_add_fee_permission(): void
    {
        $this->limitedEmployee(['all_requests.view', 'all_requests.edit']);
        $contract = $this->paidContract();
        $this->postJson('/api/admin/orders/'.$contract->id.'/charges', ['amount' => 120, 'message' => 'رسوم'])->assertStatus(403);

        $this->limitedEmployee(['all_requests.view', 'payments.add_fee']);
        $this->postJson('/api/admin/orders/'.$contract->id.'/charges', ['amount' => 120, 'message' => 'رسوم إضافة وحدة'])->assertStatus(201);
        $this->postJson('/api/admin/orders/'.$contract->id.'/charges', ['amount' => 120])->assertStatus(422)
            ->assertJsonPath('message', 'اكتب رسالة واضحة للعميل — سيقرأها كما هي.');
    }

    public function test_extra_fee_full_lifecycle_with_moyasar_webhook(): void
    {
        $employee = $this->employee('admin');
        $user = $this->customer('0551234567');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')], $user);
        $this->payFull($contract);
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/received')->assertOk();

        $created = $this->postJson('/api/admin/orders/'.$contract->id.'/charges', ['amount' => 120, 'message' => 'رسوم إضافة وحدة ثانية في إيجار'])
            ->assertStatus(201)->json('data');
        $cid = $created['charge']['id'];
        $this->assertSame('pending', $created['charge']['status']);
        $this->assertSame('partially_paid', $created['payment_state']['status']);
        $this->assertSame('مدفوع جزئياً · 249 من 369 ر.س', $created['payment_state']['label']);
        $this->assertNotNull($created['charge']['payment_url']);

        // العميل أُشعر بالرسالة كما هي.
        $notif = NotificationDispatch::query()->where('contract_id', $contract->id)->where('kind', 'charge_payment_request')->firstOrFail();
        $this->assertStringContainsString('رسوم إضافة وحدة ثانية في إيجار', $notif->body);
        $this->assertStringContainsString('120', $notif->body);

        // القائمة: شارة «بانتظار دفع فرق» + فلتر.
        $row = collect($this->getJson('/api/admin/orders?attention=charge_pending')->json('data.items'))->firstWhere('id', $contract->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['awaiting_charge']);
        $this->assertSame('بانتظار دفع فرق · 120 ر.س', $row['payment_state']['awaiting_charge_label']);

        // التوثيق مقفل.
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/notarized', ['deed_number' => '1', 'deed_type' => 'electronic'])
            ->assertStatus(422)->assertJsonPath('code', 'charge_pending');

        // رابط Moyasar لمبلغ الرسم فقط.
        Http::fake($this->moyasarInvoiceFake('inv_charge_1'));
        $link = $this->postJson('/api/admin/orders/'.$contract->id.'/charges/'.$cid.'/payment-link')->assertOk()->json('data');
        $key = Payment::chargeKey((string) $contract->uuid, $cid);
        Http::assertSent(function ($r) use ($key, $cid) {
            return str_contains($r->url(), '/v1/invoices')
                && $r['amount'] === 12000
                && $r['metadata']['contract_uuid'] === $key
                && (string) $r['metadata']['charge_id'] === (string) $cid;
        });
        $this->assertSame('https://moyasar.test/pay/inv_charge_1?lang=ar', $link['payment_url']);
        $this->assertStringStartsWith('https://wa.me/966551234567?text=', $link['whatsapp_url']);
        $this->assertStringContainsString('120', $link['message']);
        $this->assertStringContainsString('رسوم إضافة وحدة', $link['message']);
        $this->assertSame('inv_charge_1', ContractCharge::query()->find($cid)->moyasar_payment_id);

        // الـ webhook: دفعة Moyasar بالمفتاح chg-{uuid}-{id}.
        Http::fake([
            'https://api.moyasar.com/v1/payments/pay_charge_1' => Http::response([
                'id' => 'pay_charge_1', 'status' => 'paid', 'amount' => 12000, 'currency' => 'SAR',
                'source' => ['type' => 'creditcard', 'company' => 'mada'], 'metadata' => ['contract_uuid' => $key, 'charge_id' => (string) $cid],
            ], 200),
        ]);
        $this->postJson('/api/status/'.$key.'/success', ['id' => 'pay_charge_1', 'status' => 'paid']);

        $charge = ContractCharge::query()->find($cid);
        $this->assertSame('paid', $charge->status);
        $this->assertNotNull($charge->payment_id);
        $payment = Payment::query()->find($charge->payment_id);
        $this->assertSame('extra_fee', $payment->kind);
        $this->assertSame($contract->id, (int) $payment->contract_id);
        $this->assertSame('pay_charge_1', $payment->gateway_payment_id);
        $this->assertTrue((bool) $contract->fresh()->is_completed);

        // 1) سجل الدفع  2) الفاتورة  3) الشارة  4) القائمة  5) النشاط  6) الإشعارات
        $detail = $this->getJson('/api/admin/orders/'.$contract->id)->assertOk()->json('data');
        $this->assertSame('paid', $detail['payment_state']['status']);
        $this->assertSame('مدفوع · Moyasar · 369 ر.س', $detail['payment_state']['label']);
        $this->assertTrue($detail['payment_state']['can_notarize']);
        $this->assertEquals(369, $detail['payment_details']['totals']['net']);
        $this->assertEquals(120, $detail['payment_details']['totals']['extra']);
        $this->assertSame(['original', 'extra_fee'], array_column($detail['payment_details']['transactions'], 'kind'));
        $this->assertTrue($detail['invoice']['is_cumulative']);
        $this->assertEquals(369, $detail['invoice']['total_amount']);
        $this->assertSame('extra_fee', end($detail['invoice']['items'])['kind']);
        $this->assertContains('charge_paid', array_column($detail['activities'], 'action'));
        $kinds = collect($detail['notifications_sent'])->pluck('kind')->all();
        $this->assertContains('payment_success', $kinds);
        $this->assertContains('charge_paid', $kinds);
        $this->assertSame(1, EmployeeNotification::query()->where('contract_id', $contract->id)->where('kind', 'charge_paid')->count());

        $row = collect($this->getJson('/api/admin/orders?per_page=100')->json('data.items'))->firstWhere('id', $contract->id);
        $this->assertFalse($row['awaiting_charge']);
        $this->assertEquals(120, $row['paid_extra']);
        $this->assertEquals(369, $row['net_total']);
        $this->assertSame([], collect($this->getJson('/api/admin/orders?attention=charge_pending')->json('data.items'))->where('id', $contract->id)->all());

        // الدفعة تظهر في قائمة المدفوعات بنوعها.
        $rowsKinds = collect($this->getJson('/api/admin/payments?per_page=50')->json('data.items'))->pluck('kind')->all();
        $this->assertContains('extra_fee', $rowsKinds);

        // التوثيق متاح.
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/notarized', ['deed_number' => '1', 'deed_type' => 'electronic'])->assertOk();

        // العميل: الرسوم مدفوعة والفاتورة تراكمية.
        Sanctum::actingAs($user, ['*']);
        $data = $this->getJson('/api/v2/contracts/'.$contract->id)->assertOk()->json('data');
        $this->assertSame('paid', $data['charges'][0]['status']);
        $this->assertNull($data['charges'][0]['payment_url']);
        $this->assertEquals(369, $this->getJson('/api/v2/invoices/'.$contract->id)->json('data.total_amount'));

        // الـ webhook مرة ثانية ⇒ لا تكرار.
        $this->postJson('/api/status/'.$key.'/success', ['id' => 'pay_charge_1', 'status' => 'paid']);
        $this->assertSame(1, Payment::query()->where('contract_uuid', $key)->count());
    }

    public function test_customer_pay_endpoint_and_cancel(): void
    {
        $this->employee('admin');
        $user = $this->customer('0551234567');
        $other = $this->customer('0559999999');
        $contract = $this->paidContract([], $user);
        $this->payFull($contract);
        $cid = $this->postJson('/api/admin/orders/'.$contract->id.'/charges', ['amount' => 75, 'message' => 'رسوم'])->json('data.charge.id');

        Sanctum::actingAs($other, ['*']);
        $this->getJson('/api/v2/contracts/'.$contract->uuid.'/charges/'.$cid.'/pay')->assertStatus(404);

        Sanctum::actingAs($user, ['*']);
        Http::fake($this->moyasarInvoiceFake('inv_cust'));
        $res = $this->getJson('/api/v2/contracts/'.$contract->uuid.'/charges/'.$cid.'/pay')->assertOk()->json('data');
        $this->assertSame('https://moyasar.test/pay/inv_cust?lang=ar', $res['payment_url']);
        $this->assertEquals(75, $res['amount']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/invoices') && $r['amount'] === 7500);

        // الإلغاء من اللوحة.
        $this->employee('admin');
        $this->postJson('/api/admin/orders/'.$contract->id.'/charges/'.$cid.'/cancel')->assertOk()
            ->assertJsonPath('data.charge.status', 'cancelled')
            ->assertJsonPath('data.payment_state.status', 'paid');
        $this->postJson('/api/admin/orders/'.$contract->id.'/charges/'.$cid.'/payment-link')->assertStatus(422);

        Sanctum::actingAs($user, ['*']);
        $this->assertSame([], $this->getJson('/api/v2/contracts/'.$contract->id)->json('data.charges'));
        $this->getJson('/api/v2/contracts/'.$contract->uuid.'/charges/'.$cid.'/pay')->assertStatus(422);
    }

    public function test_price_difference_after_deed_type_change_and_refund_due(): void
    {
        $this->employee('admin');
        $user = $this->customer('0551234567');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee')], $user);
        $this->payFull($contract);

        $res = $this->patchJson('/api/admin/orders/'.$contract->id, ['instrument_type' => 'old_handwritten'])->assertOk()->json('data');
        $this->assertEquals(75, $res['price_difference']['difference']);
        $this->assertSame('تغيير نوع المستند: إلكتروني → ورقي', $res['price_difference']['reason']);
        $this->assertSame('pending', $res['price_difference']['charge']['status']);
        $this->assertSame('price_difference', $res['price_difference']['charge']['kind']);
        $this->assertSame('charge_pending', $res['payment_state']['notarize_block_reason']);
        $this->assertSame('مدفوع جزئياً · 249 من 324 ر.س', $res['payment_state']['label']);
        $cid = $res['price_difference']['charge']['id'];
        $this->assertSame('price_difference', NotificationDispatch::query()->where('contract_id', $contract->id)->latest('id')->value('kind'));

        // تعديل آخر لا يغيّر السعر ⇒ نفس الرسم المعلّق (واحد فقط).
        $this->patchJson('/api/admin/orders/'.$contract->id, ['neighborhood' => 'الياسمين'])->assertOk();
        $this->assertSame(1, ContractCharge::query()->where('contract_id', $contract->id)->where('status', 'pending')->count());

        // الرجوع إلى إلكتروني ⇒ يُلغى الفرق المعلّق.
        $this->patchJson('/api/admin/orders/'.$contract->id, ['instrument_type' => 'electronic'])->assertOk()
            ->assertJsonPath('data.price_difference.difference', 0)
            ->assertJsonPath('data.payment_state.status', 'paid');
        $this->assertSame('cancelled', ContractCharge::query()->find($cid)->status);

        // تغيير يرفع السعر ثم دفع الفرق عبر الـ webhook ⇒ 324 في كل مكان.
        $cid2 = $this->patchJson('/api/admin/orders/'.$contract->id, ['instrument_type' => 'old_handwritten'])->json('data.price_difference.charge.id');
        $key = Payment::chargeKey((string) $contract->uuid, $cid2);
        Http::fake([
            'https://api.moyasar.com/v1/payments/pay_diff' => Http::response([
                'id' => 'pay_diff', 'status' => 'paid', 'amount' => 7500, 'currency' => 'SAR', 'source' => ['type' => 'creditcard'],
                'metadata' => ['contract_uuid' => $key, 'charge_id' => (string) $cid2],
            ], 200),
        ]);
        $this->postJson('/api/status/'.$key.'/success', ['id' => 'pay_diff', 'status' => 'paid']);
        $detail = $this->getJson('/api/admin/orders/'.$contract->id)->json('data');
        $this->assertSame('paid', $detail['payment_state']['status']);
        $this->assertEquals(324, $detail['payment_state']['paid_total']);
        $this->assertEquals(324, $detail['invoice']['total_amount']);
        $this->assertEquals(324, $detail['payment_details']['totals']['net']);
        $this->assertSame('price_difference', ContractCharge::query()->find($cid2)->kind);
        $this->assertSame('paid', ContractCharge::query()->find($cid2)->status);

        // السعر ينخفض بعد الدفع الكامل ⇒ refund_due بدون رسم.
        $this->patchJson('/api/admin/orders/'.$contract->id, ['instrument_type' => 'electronic'])->assertOk()
            ->assertJsonPath('data.price_difference.refund_due', 75)
            ->assertJsonPath('data.payment_state.refund_due', 75)
            ->assertJsonPath('data.payment_state.status', 'paid');
        $this->assertSame(0, ContractCharge::query()->where('contract_id', $contract->id)->where('status', 'pending')->count());
        $this->assertContains('refund_due', ContractActivity::query()->where('contract_id', $contract->id)->pluck('action')->all());

        // الاسترجاع الجزئي بضغطة واحدة (الخدمة الموجودة) ⇒ ينعكس.
        $paymentId = $detail['payments'][0]['id'];
        Http::fake([
            'https://api.moyasar.com/v1/payments/pay_e_1/refund' => Http::response(['id' => 'pay_e_1', 'status' => 'refunded', 'amount' => 24900, 'refunded' => 7500, 'currency' => 'SAR'], 200),
        ]);
        $this->postJson('/api/admin/payments/'.$paymentId.'/refund', ['amount' => 75, 'reason' => 'فرق لصالح العميل'])->assertOk();
        $state = $this->getJson('/api/admin/orders/'.$contract->id)->json('data.payment_state');
        $this->assertSame('partially_refunded', $state['status']);
        $this->assertEquals(75, $state['refunded_total']);
        $this->assertEquals(249, $state['net_total']);
        $this->assertEquals(0, $state['refund_due']);
        $this->assertTrue($state['can_notarize']);
        $invoice = $this->getJson('/api/admin/orders/'.$contract->id)->json('data.invoice');
        $this->assertEquals(249, $invoice['total_amount']);
        $this->assertEquals(75, $invoice['refunded_total']);
    }

    public function test_legacy_admin_update_also_recomputes_difference(): void
    {
        $this->employee('admin');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee')]);
        $this->payFull($contract);

        $this->postJson('/api/admin/orders/'.$contract->id, ['instrument_type' => 'old_handwritten'])->assertOk();
        $pending = ContractCharge::query()->where('contract_id', $contract->id)->where('status', 'pending')->first();
        $this->assertNotNull($pending);
        $this->assertEquals(75, $pending->amount);
    }
}
