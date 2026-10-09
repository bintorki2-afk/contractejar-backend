<?php

namespace Tests\Feature\BatchE;

use App\Models\Payment;
use App\Services\Admin\WeeklyOwnerReportService;
use Illuminate\Support\Facades\Http;

/**
 * دفعة (هـ) — 2.7 / E5: الرقم نفسه في كل مكان — رسوم إضافية 120 + فرق سعر 75 + استرجاع 50:
 * التقارير (overview/sales/performance) · KPI الموظف · التقرير الأسبوعي · تصدير Excel/CSV · قائمة المدفوعات.
 */
class ReportsReflectionTest extends BatchETestCase
{
    public function test_numbers_are_consistent_across_reports_kpi_weekly_and_export(): void
    {
        $employee = $this->employee('admin');
        $user = $this->customer('0551234567');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')], $user);
        $this->payFull($contract); // 249
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/received')->assertOk();

        // رسوم إضافية 120 تُدفع عبر الـ webhook.
        $cid = $this->postJson('/api/admin/orders/'.$contract->id.'/charges', ['amount' => 120, 'message' => 'رسوم وحدة'])->json('data.charge.id');
        $key = Payment::chargeKey((string) $contract->uuid, $cid);
        Http::fake(['https://api.moyasar.com/v1/payments/pay_fee' => Http::response(['id' => 'pay_fee', 'status' => 'paid', 'amount' => 12000, 'currency' => 'SAR', 'source' => ['type' => 'creditcard'], 'metadata' => ['contract_uuid' => $key, 'charge_id' => (string) $cid]], 200)]);
        $this->postJson('/api/status/'.$key.'/success', ['id' => 'pay_fee', 'status' => 'paid']);

        // فرق سعر 75 (ورقي) يُدفع عبر الـ webhook.
        $did = $this->patchJson('/api/admin/orders/'.$contract->id, ['instrument_type' => 'old_handwritten'])->json('data.price_difference.charge.id');
        $dkey = Payment::chargeKey((string) $contract->uuid, $did);
        Http::fake(['https://api.moyasar.com/v1/payments/pay_diff' => Http::response(['id' => 'pay_diff', 'status' => 'paid', 'amount' => 7500, 'currency' => 'SAR', 'source' => ['type' => 'creditcard'], 'metadata' => ['contract_uuid' => $dkey, 'charge_id' => (string) $did]], 200)]);
        $this->postJson('/api/status/'.$dkey.'/success', ['id' => 'pay_diff', 'status' => 'paid']);

        // استرجاع جزئي 50 من الدفعة الأصلية.
        $paymentId = Payment::query()->where('contract_uuid', (string) $contract->uuid)->where('status', 'success')->value('id');
        Http::fake(['https://api.moyasar.com/v1/payments/pay_e_1/refund' => Http::response(['id' => 'pay_e_1', 'status' => 'refunded', 'amount' => 24900, 'refunded' => 5000, 'currency' => 'SAR'], 200)]);
        $this->postJson('/api/admin/payments/'.$paymentId.'/refund', ['amount' => 50, 'reason' => 'تعويض'])->assertOk();

        // 1) سجل الدفع + الشارة + الفاتورة
        $detail = $this->getJson('/api/admin/orders/'.$contract->id)->json('data');
        $this->assertEquals(444, $detail['payment_state']['paid_total']);   // 249 + 120 + 75
        $this->assertEquals(50, $detail['payment_state']['refunded_total']);
        $this->assertEquals(394, $detail['payment_state']['net_total']);
        $this->assertSame('partially_refunded', $detail['payment_state']['status']);
        $this->assertSame(['original', 'extra_fee', 'price_difference', 'refund'], array_column($detail['payment_details']['transactions'], 'kind'));
        $this->assertEquals(['original' => 249, 'extra' => 195, 'refunded' => 50, 'net' => 394], array_intersect_key($detail['payment_details']['totals'], array_flip(['original', 'extra', 'refunded', 'net'])));
        $this->assertEquals(394, $detail['invoice']['total_amount']);
        $this->assertEquals(195, $detail['invoice']['extra_total']);
        $this->assertEquals(50, $detail['invoice']['refunded_total']);

        // 2) القائمة + التصدير
        $row = collect($this->getJson('/api/admin/orders?per_page=100')->json('data.items'))->firstWhere('id', $contract->id);
        $this->assertEquals(249, $row['paid_original']);
        $this->assertEquals(195, $row['paid_extra']);
        $this->assertEquals(50, $row['refunded_total']);
        $this->assertEquals(394, $row['net_total']);
        $csv = $this->get('/api/admin/orders/export?format=csv', ['Accept' => 'application/json'])->assertOk()->getContent();
        $this->assertStringContainsString('المدفوع الأصلي', $csv);
        $line = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, (string) $contract->uuid));
        $this->assertNotNull($line);
        $this->assertStringContainsString(',249,195,50,394,', $line);
        $xlsx = $this->get('/api/admin/orders/export?format=xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $xlsx->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', $xlsx->getContent());

        // 3) التقارير المالية
        $overview = $this->getJson('/api/admin/reports/overview?range=today')->json('data');
        $this->assertEquals(120, $overview['extra_fees']);
        $this->assertEquals(75, $overview['price_differences']);
        $this->assertEquals(50, $overview['refunds']);
        $this->assertEquals(394, $overview['net_revenue']);
        $cards = collect($overview['cards'])->keyBy('key');
        $this->assertEquals(444, $cards['revenue']['value']);
        $this->assertEquals(394, $cards['net_revenue']['value']);
        $this->assertEquals(120, $cards['extra_fees']['value']);
        $this->assertEquals(75, $cards['price_differences']['value']);

        $sales = $this->getJson('/api/admin/reports/sales?period=today')->json('data');
        $this->assertEquals(444, $sales['kpis']['total_sales']);
        $this->assertEquals(120, $sales['kpis']['extra_fees']);
        $this->assertEquals(75, $sales['kpis']['price_differences']);
        $this->assertEquals(50, $sales['kpis']['refunds']);
        $this->assertEquals(394, $sales['kpis']['net_revenue']);
        $this->assertEquals(249, $sales['kpis']['original_revenue']);
        $this->assertEquals(120, $sales['summary']['extra_fees']);

        $perf = $this->getJson('/api/admin/reports/performance?period=today')->json('data.kpis');
        $this->assertEquals(120, $perf['extra_fees']);
        $this->assertEquals(75, $perf['price_differences']);
        $this->assertEquals(50, $perf['refunds']);
        $this->assertEquals(394, $perf['net_revenue']);

        // 4) قائمة المدفوعات تحمل النوع
        $payments = collect($this->getJson('/api/admin/payments?per_page=50')->json('data.items'));
        $this->assertSame(['extra_fee', 'original', 'price_difference'], $payments->pluck('kind')->sort()->values()->all());
        $this->assertSame((string) $contract->uuid, $payments->firstWhere('kind', 'extra_fee')['contract_uuid']);

        // 5) KPI الموظف
        $kpi = $this->getJson('/api/admin/employees/'.$employee->id.'/kpis?period=today')->json('data');
        $this->assertSame(1, $kpi['fees_added_count']);
        $this->assertEquals(120, $kpi['fees_added_amount']);
        $this->assertSame(1, $kpi['price_difference_count']);
        $this->assertSame(0, $kpi['data_requests_count']);
        $cards = collect($kpi['cards'])->keyBy('key');
        $this->assertSame(1, $cards['fees_added_count']['value']);

        // 6) التقرير الأسبوعي
        $service = app(WeeklyOwnerReportService::class);
        $report = $service->build();
        $this->assertSame(1, $report['extra_fees_count']);
        $this->assertEquals(120, $report['extra_fees_amount']);
        $this->assertSame(1, $report['price_differences_count']);
        $this->assertEquals(75, $report['price_differences_amount']);
        $this->assertSame(1, $report['refunds_count']);
        $text = $service->text($report);
        $this->assertStringContainsString('رسوم إضافية: 1 (120 ر.س) · فروقات: 1 (75 ر.س) · استرجاعات: 1 (50 ر.س)', $text);

        // 7) العميل: فاتورة تراكمية بنفس الصافي
        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
        $invoice = $this->getJson('/api/v2/invoices/'.$contract->id)->json('data');
        $this->assertEquals(394, $invoice['total_amount']);
        $this->assertTrue($invoice['is_cumulative']);
        $this->assertSame(['fee', 'extra_fee', 'price_difference', 'refund'], array_map(fn ($i) => $i['kind'] ?? 'fee', $invoice['items']));
    }
}
