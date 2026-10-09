<?php

namespace Tests\Feature\BatchE;

use App\Models\ContractCharge;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Payments\ContractPaymentState;
use App\Support\ContractPricing;

/**
 * دفعة (هـ) — 2.1: مصفوفة حالة الدفع (غير مدفوع / مدفوع Moyasar / حوالة / جزئي / مسترجع جزئياً / مسترجع / مختلط).
 */
class PaymentStateTest extends BatchETestCase
{
    public function test_unpaid_order(): void
    {
        $this->employee('admin');
        $contract = $this->contract();
        $state = app(ContractPaymentState::class)->state($contract);

        $this->assertSame('unpaid', $state['status']);
        $this->assertNull($state['method']);
        $this->assertSame(249.0, $state['due_total']);
        $this->assertSame(0.0, $state['paid_total']);
        $this->assertSame(249.0, $state['outstanding']);
        $this->assertFalse($state['can_notarize']);
        $this->assertSame('payment_required', $state['notarize_block_reason']);
        $this->assertSame('غير مدفوع · 249 ر.س', $state['label']);

        $detail = $this->getJson('/api/admin/orders/'.$contract->id)->assertOk()->json('data');
        $this->assertSame('unpaid', $detail['payment_state']['status']);
        $this->assertSame([], $detail['payment_details']['transactions']);
        $this->assertSame(249.0, (float) $detail['payment_details']['totals']['due']);
    }

    public function test_paid_by_moyasar(): void
    {
        $this->employee('admin');
        $contract = $this->paidContract();
        $this->payFull($contract);
        $state = app(ContractPaymentState::class)->state($contract->fresh());

        $this->assertSame('paid', $state['status']);
        $this->assertSame('moyasar', $state['method']);
        $this->assertSame('مدفوع · Moyasar · 249 ر.س', $state['label']);
        $this->assertTrue($state['can_notarize']);
        $this->assertNull($state['notarize_block_reason']);
        $this->assertSame(249.0, $state['net_total']);
    }

    public function test_paid_by_bank_transfer_and_mixed(): void
    {
        $this->employee('admin');
        $contract = $this->paidContract();
        Payment::query()->create([
            'name' => 'Bank', 'amount' => 249, 'payment_date' => now()->toDateString(), 'contract_uuid' => (string) $contract->uuid, 'contract_id' => $contract->id,
            'tran_currency' => 'SAR', 'payment_method' => Payment::METHOD_BANK_TRANSFER, 'status' => 'success', 'kind' => Payment::KIND_BANK_TRANSFER,
        ]);
        $state = app(ContractPaymentState::class)->state($contract->fresh());
        $this->assertSame('paid', $state['status']);
        $this->assertSame('bank_transfer', $state['method']);
        $this->assertSame('مدفوع · حوالة · 249 ر.س', $state['label']);

        // رسوم إضافية مدفوعة عبر Moyasar ⇒ مختلط.
        $charge = ContractCharge::query()->create(['contract_id' => $contract->id, 'kind' => 'extra_fee', 'amount' => 100, 'message' => 'x', 'status' => 'paid', 'paid_at' => now()]);
        Payment::query()->create([
            'name' => 'Charge', 'amount' => 100, 'payment_date' => now()->toDateString(), 'contract_uuid' => Payment::chargeKey((string) $contract->uuid, $charge->id), 'contract_id' => $contract->id,
            'charge_id' => $charge->id, 'kind' => 'extra_fee', 'tran_currency' => 'SAR', 'payment_method' => 'creditcard', 'status' => 'success',
        ]);
        $state = app(ContractPaymentState::class)->state($contract->fresh());
        $this->assertSame('paid', $state['status']);
        $this->assertSame('mixed', $state['method']);
        $this->assertSame(349.0, $state['due_total']);
        $this->assertSame(349.0, $state['paid_total']);
        $this->assertSame(100.0, $state['extra_due']);
    }

    public function test_partially_paid_with_pending_charge_blocks_notarization(): void
    {
        $this->employee('admin');
        $contract = $this->paidContract();
        $this->payFull($contract);
        ContractCharge::query()->create(['contract_id' => $contract->id, 'kind' => 'extra_fee', 'amount' => 75, 'message' => 'رسوم', 'status' => 'pending']);

        $state = app(ContractPaymentState::class)->state($contract->fresh());
        $this->assertSame('partially_paid', $state['status']);
        $this->assertSame(324.0, $state['due_total']);
        $this->assertSame(75.0, $state['outstanding']);
        $this->assertSame('مدفوع جزئياً · 249 من 324 ر.س', $state['label']);
        $this->assertFalse($state['can_notarize']);
        $this->assertSame('charge_pending', $state['notarize_block_reason']);
        $this->assertSame(1, $state['pending_charges_count']);
    }

    public function test_refunded_and_partially_refunded(): void
    {
        $this->employee('admin');
        $contract = $this->paidContract();
        $payment = $this->payFull($contract);

        Refund::query()->create(['payment_id' => $payment->id, 'contract_id' => $contract->id, 'contract_uuid' => (string) $contract->uuid, 'amount' => 100, 'currency' => 'SAR', 'status' => Refund::STATUS_SUCCEEDED, 'reason' => 'جزئي']);
        $state = app(ContractPaymentState::class)->state($contract->fresh());
        $this->assertSame('partially_refunded', $state['status']);
        $this->assertSame(100.0, $state['refunded_total']);
        $this->assertSame(149.0, $state['net_total']);
        $this->assertSame('مسترجع جزئياً · 100 من 249 ر.س', $state['label']);
        $this->assertSame(100.0, $state['outstanding']);
        $this->assertFalse($state['can_notarize']);

        Refund::query()->create(['payment_id' => $payment->id, 'contract_id' => $contract->id, 'contract_uuid' => (string) $contract->uuid, 'amount' => 149, 'currency' => 'SAR', 'status' => Refund::STATUS_SUCCEEDED, 'reason' => 'الباقي']);
        $state = app(ContractPaymentState::class)->state($contract->fresh());
        $this->assertSame('refunded', $state['status']);
        $this->assertSame('مسترجع · 249 ر.س', $state['label']);
        $this->assertSame(0.0, $state['net_total']);

        $details = app(ContractPaymentState::class)->details($contract->fresh());
        $this->assertSame(249.0, $details['totals']['original']);
        $this->assertSame(249.0, $details['totals']['refunded']);
        $this->assertSame(0.0, $details['totals']['net']);
        $kinds = array_column($details['transactions'], 'kind');
        $this->assertSame(['original', 'refund', 'refund'], $kinds);
        $this->assertCount(2, array_filter($details['lines'], fn ($l) => $l['kind'] === 'refund'));
        $this->assertStringContainsString('/api/v2/invoices/print/'.$contract->id, $details['invoice_url']);
    }

    public function test_refund_due_when_price_drops_below_paid(): void
    {
        $this->employee('admin');
        $contract = $this->paidContract(['instrument_type' => 'old_handwritten']);
        $this->assertSame(324.0, ContractPricing::total($contract));
        $this->payFull($contract);
        $contract->forceFill(['instrument_type' => 'electronic'])->save();

        $state = app(ContractPaymentState::class)->state($contract->fresh());
        $this->assertSame('paid', $state['status']);
        $this->assertSame(75.0, $state['refund_due']);
        $this->assertSame(0.0, $state['outstanding']);
        $this->assertTrue($state['can_notarize']);
    }

    public function test_printable_invoice_and_customer_payloads_carry_state(): void
    {
        $this->employee('admin');
        $user = $this->customer('0551234567');
        $contract = $this->paidContract([], $user);
        $this->payFull($contract);

        $url = (string) $this->getJson('/api/admin/orders/'.$contract->id)->json('data.payment_details.invoice_url');
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('الفاتورة', $html);
        $this->assertStringContainsString('249', $html);
        $this->get('/api/v2/invoices/print/'.$contract->id)->assertStatus(403); // بلا توقيع

        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
        $data = $this->getJson('/api/v2/contracts/'.$contract->id)->assertOk()->json('data');
        $this->assertSame('paid', $data['payment_state']['status']);
        $this->assertSame([], $data['charges']);
        $this->assertSame([], $data['pending_data_requests']);
        $this->assertSame(249.0, (float) $data['payment_details']['totals']['net']);

        $invoice = $this->getJson('/api/v2/invoices/'.$contract->id)->assertOk()->json('data');
        $this->assertFalse($invoice['is_cumulative']);
        $this->assertSame(249.0, (float) $invoice['total_amount']);
        $this->assertSame('paid', $invoice['payment_state']['status']);
    }
}
