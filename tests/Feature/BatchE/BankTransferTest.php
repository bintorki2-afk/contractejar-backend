<?php

namespace Tests\Feature\BatchE;

use App\Models\ContractActivity;
use App\Models\ContractCharge;
use App\Models\ContractStatus;
use App\Models\NotificationDispatch;
use App\Models\Payment;
use Illuminate\Support\Facades\Storage;

/**
 * دفعة (هـ) — 2.2: تسجيل حوالة بنكية مع الإيصال.
 */
class BankTransferTest extends BatchETestCase
{
    public function test_requires_record_transfer_permission(): void
    {
        $this->limitedEmployee(['all_requests.view', 'all_requests.edit']);
        $contract = $this->contract();
        $this->post('/api/admin/orders/'.$contract->id.'/payments/bank-transfer', ['amount' => 249, 'receipt' => $this->fakePng()], ['Accept' => 'application/json'])
            ->assertStatus(403);

        $this->limitedEmployee(['all_requests.view', 'payments.record_transfer']);
        $this->post('/api/admin/orders/'.$contract->id.'/payments/bank-transfer', ['amount' => 249, 'receipt' => $this->fakePng()], ['Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_bank_transfer_settles_unpaid_order_and_reflects_everywhere(): void
    {
        Storage::fake('local');
        $employee = $this->employee('admin');
        $user = $this->customer('0551234567');
        $contract = $this->contract(['contract_status_id' => $this->statusId('new')], $user);

        // الموظف يستلم طلباً غير مدفوع ⇒ «وثّقت» مقفل.
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/received')->assertOk();
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/notarized', ['deed_number' => '1', 'deed_type' => 'electronic'])
            ->assertStatus(422)->assertJsonPath('code', 'payment_required');

        // بلا إيصال ⇒ 422
        $this->post('/api/admin/orders/'.$contract->id.'/payments/bank-transfer', ['amount' => 249], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'صورة الإيصال مطلوبة.');

        $res = $this->post('/api/admin/orders/'.$contract->id.'/payments/bank-transfer', [
            'amount' => 249, 'receipt' => $this->fakePng(), 'reference' => 'TRF-77', 'paid_at' => '2026-10-09', 'note' => 'حوالة الراجحي',
        ], ['Accept' => 'application/json'])->assertOk()->json('data');

        $this->assertSame('paid', $res['payment_state']['status']);
        $this->assertSame('bank_transfer', $res['payment_state']['method']);
        $this->assertSame('مدفوع · حوالة · 249 ر.س', $res['payment_state']['label']);
        $this->assertTrue($res['payment_state']['can_notarize']);
        $this->assertSame('bank_transfer', $res['transaction']['kind']);
        $this->assertSame('TRF-77', $res['transaction']['reference']);
        $this->assertSame($employee->id, $res['transaction']['employee']['id']);
        $this->assertStringContainsString('/api/v2/payments/', $res['transaction']['receipt_url']);
        $this->assertTrue($res['contract']['is_completed']);
        $this->assertSame('received_by_employee', $res['contract']['status_key']);

        $payment = Payment::query()->where('contract_uuid', (string) $contract->uuid)->where('status', 'success')->firstOrFail();
        $this->assertSame(Payment::METHOD_BANK_TRANSFER, $payment->payment_method);
        $this->assertSame($contract->id, (int) $payment->contract_id);
        $this->assertSame('2026-10-09', substr((string) $payment->payment_date, 0, 10));
        Storage::disk('local')->assertExists($payment->receipt_path);

        // الإيصال يُقرأ عبر رابط موقّع فقط.
        $this->get($res['transaction']['receipt_url'])->assertOk();
        $this->get('/api/v2/payments/'.$payment->id.'/receipt')->assertStatus(403);

        // الانعكاسات: الطلب مدفوع، النشاط، الإشعار، الفاتورة.
        $this->assertTrue((bool) $contract->fresh()->is_completed);
        $actions = ContractActivity::query()->where('contract_id', $contract->id)->pluck('action')->all();
        $this->assertContains('bank_transfer_recorded', $actions);
        $this->assertContains('payment', $actions);
        $kinds = NotificationDispatch::query()->where('contract_id', $contract->id)->pluck('kind')->all();
        $this->assertSame(1, count(array_filter($kinds, fn ($k) => $k === 'payment_success')));
        $this->assertStringContainsString('حوالتك البنكية', (string) NotificationDispatch::query()->where('contract_id', $contract->id)->where('kind', 'payment_success')->value('body'));

        $detail = $this->getJson('/api/admin/orders/'.$contract->id)->assertOk()->json('data');
        $this->assertSame('paid', $detail['invoice']['status']);
        $this->assertSame('حوالة', $detail['invoice']['payment_method_label']);
        $this->assertSame(249.0, (float) $detail['invoice']['total_amount']);
        $this->assertSame('bank_transfer', $detail['payments'][0]['kind']);

        // التوثيق متاح الآن.
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/notarized', ['deed_number' => '1', 'deed_type' => 'electronic'])->assertOk();

        // حوالة ثانية على طلب مدفوع بالكامل ⇒ 422
        $this->post('/api/admin/orders/'.$contract->id.'/payments/bank-transfer', ['amount' => 10, 'receipt' => $this->fakePng()], ['Accept' => 'application/json'])
            ->assertStatus(422);

        // العميل يرى الحالة.
        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
        $this->getJson('/api/v2/contracts/'.$contract->id)->assertOk()
            ->assertJsonPath('data.payment_state.status', 'paid')
            ->assertJsonPath('data.payment_state.method', 'bank_transfer');
    }

    public function test_bank_transfer_for_a_pending_charge(): void
    {
        Storage::fake('local');
        $this->employee('admin');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')]);
        $this->payFull($contract);
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/received')->assertOk();
        $charge = ContractCharge::query()->create(['contract_id' => $contract->id, 'kind' => 'extra_fee', 'amount' => 120, 'message' => 'رسوم وحدة', 'status' => 'pending']);
        $charge->forceFill(['payment_key' => Payment::chargeKey((string) $contract->uuid, $charge->id)])->save();

        $this->assertSame('charge_pending', $this->getJson('/api/admin/orders/'.$contract->id.'/stages')->json('data.next_stage_lock_reason'));

        $res = $this->post('/api/admin/orders/'.$contract->id.'/payments/bank-transfer', [
            'amount' => 120, 'receipt' => $this->fakePng(), 'charge_id' => $charge->id,
        ], ['Accept' => 'application/json'])->assertOk()->json('data');

        $this->assertSame('paid', $res['charge']['status']);
        $this->assertSame('extra_fee', $res['transaction']['kind']);
        $this->assertSame('bank_transfer', $res['transaction']['method']);
        $this->assertSame('paid', $res['payment_state']['status']);
        $this->assertSame('mixed', $res['payment_state']['method']);
        $this->assertEquals(369, $res['payment_state']['paid_total']);
        $this->assertTrue($res['payment_state']['can_notarize']);
        $this->assertEquals(120, $res['payment_details']['totals']['extra']);
        $this->assertEquals(369, $res['payment_details']['totals']['net']);

        $invoice = $this->getJson('/api/admin/orders/'.$contract->id)->json('data.invoice');
        $this->assertTrue($invoice['is_cumulative']);
        $this->assertSame(369.0, (float) $invoice['total_amount']);
        $this->assertContains('charge_paid', ContractActivity::query()->where('contract_id', $contract->id)->pluck('action')->all());
        $this->assertSame(ContractStatus::idFor('received_by_employee'), (int) $contract->fresh()->contract_status_id);
    }
}
