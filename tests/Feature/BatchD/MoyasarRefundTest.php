<?php

namespace Tests\Feature\BatchD;

use App\Models\ContractStatus;
use App\Models\Offer;
use App\Models\Refund;
use App\Models\RefundableContract;
use App\Models\Role;
use App\Services\ContractInvoiceService;
use Illuminate\Support\Facades\Http;

/**
 * دفعة (د) — ب8: الاسترجاع عبر Moyasar (كلي/جزئي) — idempotent + انعكاس على الطلب والفاتورة والإشعار.
 */
class MoyasarRefundTest extends BatchDTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.moyasar.driver' => 'moyasar']); // بوابة حقيقية (Http::fake)
    }

    private function paidOrder(float $amount = 249): array
    {
        $user = $this->customer();
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee')], $user);
        $payment = $this->payment($contract, $amount, 'pay_live_1');
        $payment->forceFill(['gateway_payment_id' => 'pay_live_1'])->save();

        return [$contract, $payment, $user];
    }

    public function test_full_refund_calls_moyasar_and_reflects_everywhere(): void
    {
        $this->employee('admin');
        [$contract, $payment, $user] = $this->paidOrder();
        Http::fake([
            'https://api.moyasar.com/v1/payments/pay_live_1/refund' => Http::response([
                'id' => 'pay_live_1', 'status' => 'refunded', 'amount' => 24900, 'refunded' => 24900, 'refunded_at' => '2026-10-09T10:00:00Z', 'currency' => 'SAR',
            ], 200),
        ]);

        $res = $this->postJson('/api/admin/payments/'.$payment->id.'/refund', ['reason' => 'طلب العميل الإلغاء'])->assertOk()->json('data');

        Http::assertSent(fn ($r) => $r->url() === 'https://api.moyasar.com/v1/payments/pay_live_1/refund' && $r->method() === 'POST' && ! isset($r->data()['amount']));
        $this->assertSame('succeeded', $res['status']);
        $this->assertEquals(249, $res['amount']);
        $this->assertSame('full', $payment->fresh()->refund_status);
        $this->assertSame(ContractStatus::refundedId(), (int) $contract->fresh()->contract_status_id);
        $this->assertTrue((bool) RefundableContract::query()->where('contract_id', $contract->id)->value('is_refunded'));

        $invoice = app(ContractInvoiceService::class)->forContract($contract->fresh());
        $this->assertSame('refunded', $invoice['status']);
        $this->assertSame('مُسترجعة', $invoice['status_label']);
        $this->assertEquals(249, $invoice['refunded_amount']);

        $offer = Offer::query()->where('user_id', $user->id)->where('kind', 'refund')->firstOrFail();
        $this->assertStringContainsString('249', $offer->body);

        $detail = $this->getJson('/api/admin/orders/'.$contract->id)->assertOk()->json('data');
        $this->assertSame(0.0, (float) $detail['payments'][0]['refundable_amount']);
        $this->assertCount(1, $detail['refunds']);
        $this->assertContains('refunded', array_column($detail['activities'], 'action'));

        // تكرار ⇒ مرفوض (idempotent).
        $this->postJson('/api/admin/payments/'.$payment->id.'/refund', ['reason' => 'مرة ثانية'])->assertStatus(422);
        Http::assertSentCount(1);

        $this->assertSame(1, $this->getJson('/api/admin/payments/refunds')->assertOk()->json('data.summary.succeeded_count'));
    }

    public function test_partial_refund_in_halalas_keeps_status(): void
    {
        $this->employee('admin');
        [$contract, $payment] = $this->paidOrder(249);
        Http::fake(['https://api.moyasar.com/v1/payments/pay_live_1/refund' => Http::response(['id' => 'pay_live_1', 'status' => 'paid', 'refunded' => 5000], 200)]);

        $this->postJson('/api/admin/payments/'.$payment->id.'/refund', ['amount' => 50, 'reason' => 'خصم تعويض'])->assertOk();
        Http::assertSent(fn ($r) => ($r->data()['amount'] ?? null) === 5000);

        $this->assertSame('partial', $payment->fresh()->refund_status);
        $this->assertSame(50.0, (float) $payment->fresh()->refunded_amount);
        $this->assertNotSame(ContractStatus::refundedId(), (int) $contract->fresh()->contract_status_id);
        $invoice = app(ContractInvoiceService::class)->forContract($contract->fresh());
        $this->assertSame('partially_refunded', $invoice['status']);

        // أكثر من المتبقي ⇒ 422
        $this->postJson('/api/admin/payments/'.$payment->id.'/refund', ['amount' => 500, 'reason' => 'كثير'])->assertStatus(422);
    }

    public function test_gateway_failure_is_recorded_and_returns_502(): void
    {
        $this->employee('admin');
        [$contract, $payment] = $this->paidOrder();
        Http::fake(['https://api.moyasar.com/v1/payments/pay_live_1/refund' => Http::response(['message' => 'Payment already refunded'], 400)]);

        $this->postJson('/api/admin/payments/'.$payment->id.'/refund', ['reason' => 'تجربة'])->assertStatus(502);
        $this->assertSame('failed', Refund::query()->value('status'));
        $this->assertSame(0.0, (float) $payment->fresh()->refunded_amount);
        $this->assertNotSame(ContractStatus::refundedId(), (int) $contract->fresh()->contract_status_id);
    }

    public function test_permission_payments_refund_is_required(): void
    {
        $manager = $this->employee('manager');
        [, $payment] = $this->paidOrder();
        $this->postJson('/api/admin/payments/'.$payment->id.'/refund', ['reason' => 'x x x'])->assertStatus(403);

        $role = Role::query()->where('name', 'manager')->first();
        $perm = app(\App\Modules\Employees\Services\RolePermissionResolver::class)->findOrCreatePermission('payments', 'refund');
        $this->assertNotNull($perm);
        $this->assertSame('استرجاع المدفوعات', $perm->action_label_ar);
        $this->assertNull(app(\App\Modules\Employees\Services\RolePermissionResolver::class)->findOrCreatePermission('analytics', 'refund'));
        $role->permissions()->attach($perm->id);
        \Laravel\Sanctum\Sanctum::actingAs($manager->fresh());

        Http::fake(['*' => Http::response(['id' => 'pay_live_1', 'status' => 'refunded'], 200)]);
        $this->postJson('/api/admin/payments/'.$payment->id.'/refund', ['reason' => 'مسموح الآن'])->assertOk();
    }
}
