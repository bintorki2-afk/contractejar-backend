<?php

namespace Tests\Feature\BatchD;

use App\Models\ContractStatusHistory;
use Illuminate\Support\Facades\DB;

/**
 * دفعة (د) — ب21: «نظرة عامة» — 6 أرقام من نطاق واحد.
 */
class ReportsOverviewTest extends BatchDTestCase
{
    public function test_six_numbers(): void
    {
        $this->employee('admin');
        // المصدر (utm_source) محفوظ على العميل.
        $google = $this->customer('0551000011');
        $google->forceFill(['utm_source' => 'google'])->save();
        $tiktok = $this->customer('0551000012');
        $tiktok->forceFill(['utm_source' => 'tiktok'])->save();
        $a = $this->paidContract([], $google);
        $this->payment($a, 249, 'p1');
        $b = $this->paidContract([], $google);
        $this->payment($b, 349, 'p2');
        $this->contract([], $tiktok);
        $this->contract(['step' => 2]); // ليس طلباً
        $h1 = ContractStatusHistory::query()->create(['contract_id' => $a->id, 'status_type' => 'system', 'status' => 'paid', 'status_label' => 'تم الدفع', 'source' => 'payment']);
        $h2 = ContractStatusHistory::query()->create(['contract_id' => $a->id, 'status_type' => 'contract', 'status' => 'ejar_authenticated', 'status_label' => 'توثيق', 'source' => 'admin']);
        DB::table('contract_status_histories')->where('id', $h1->id)->update(['created_at' => now()->subHours(10)]);

        $data = $this->getJson('/api/admin/reports/overview?range=week')->assertOk()->json('data');
        $cards = collect($data['cards'])->keyBy('key');

        // دفعة (هـ): 6 بطاقات + 4 بطاقات مالية (رسوم إضافية / فروقات / استرجاعات / صافي).
        $this->assertCount(10, $data['cards']);
        $this->assertSame(['orders_today', 'orders_week', 'revenue', 'extra_fees', 'price_differences', 'refunds', 'net_revenue', 'avg_notarization_hours', 'completion_rate', 'top_source'], $cards->keys()->all());
        $this->assertSame(3, $cards['orders_today']['value']);
        $this->assertSame(3, $cards['orders_week']['value']);
        $this->assertEquals(598, $cards['revenue']['value']);
        $this->assertEquals(10.0, $cards['avg_notarization_hours']['value']);
        $this->assertSame(50, $cards['completion_rate']['value']);
        $this->assertSame('google', $cards['top_source']['value']['key']);
        $this->assertSame(2, $cards['top_source']['value']['orders']);

        $this->getJson('/api/admin/reports/overview?range=bogus')->assertOk()->assertJsonPath('data.range', 'week');
    }
}
