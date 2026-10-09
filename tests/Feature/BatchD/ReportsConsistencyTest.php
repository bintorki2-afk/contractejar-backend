<?php

namespace Tests\Feature\BatchD;

use App\Models\ContractStatus;
use App\Modules\Users\Models\User;

/**
 * دفعة (د) — ب6: اتساق التقارير — الإلغاء بالحالة، القمع (16 − 3 = 13 = 81%)، نسبة الإلغاء ÷ البداية،
 * نطاق «عميل» واحد بين صفحة العملاء والتقارير، والموظف بلا طلبات ⇒ null.
 */
class ReportsConsistencyTest extends BatchDTestCase
{
    private function seedFunnel(): void
    {
        $user = $this->customer();
        // 16 طلباً بدأها العملاء: 3 مدفوعة، 2 ملغاة، 4 مسودات مبكرة، الباقي غير مدفوع.
        for ($i = 0; $i < 3; $i++) {
            $this->paidContract(['contract_status_id' => $this->statusId('under_review')], $user);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->contract(['contract_status_id' => $this->statusId('cancelled')], $user);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->contract(['step' => 2], $user);
        }
        for ($i = 0; $i < 7; $i++) {
            $this->contract([], $user);
        }
        // محذوف — لا يُحسب بداية ولا إلغاء.
        $this->contract(['is_delete' => 1, 'contract_status_id' => $this->statusId('cancelled')], $user);
    }

    public function test_funnel_drop_off_and_cancellation_rate(): void
    {
        $this->employee('admin');
        $this->seedFunnel();

        $data = $this->getJson('/api/admin/reports/performance?period=all')->assertOk()->json('data');

        $this->assertSame(16, $data['funnel_summary']['started']);
        $this->assertSame(3, $data['funnel_summary']['completed']);
        $this->assertSame(13, $data['funnel_summary']['drop_off']);
        $this->assertSame(81, $data['funnel_summary']['drop_off_percent']);
        $this->assertSame(13, $data['conversion_leakage']['count']);
        $this->assertSame(81, $data['conversion_leakage']['percent']);
        $this->assertSame(2, $data['kpis']['canceled_count']);
        $this->assertSame(2, $data['funnel_summary']['cancelled']);
        $this->assertSame(13, $data['funnel_summary']['cancellation_rate']); // 2 ÷ 16
        $rates = collect($data['conversion_rates'])->pluck('value', 'label');
        $this->assertSame(13, $rates['نسبة الإلغاء']);

        $orders = $this->getJson('/api/admin/reports/orders?period=all')->assertOk()->json('data');
        $this->assertSame(2, $orders['kpis']['canceled']);
    }

    public function test_customers_scope_matches_clients_page(): void
    {
        $this->employee('admin');
        $this->seedFunnel();
        // زائر بلا طلب (لا يُعد عميلاً) + زائر بطلب (يُعد) + حساب مدموج (لا يُعد).
        User::query()->create(['is_guest' => true, 'is_active' => true, 'mobile' => '0500000001']);
        $guestWithOrder = User::query()->create(['is_guest' => true, 'is_active' => true, 'mobile' => '0500000002']);
        $this->contract([], $guestWithOrder);
        $target = $this->customer('0500000003');
        $merged = User::query()->create(['is_guest' => true, 'is_active' => true, 'mobile' => '0500000004']);
        $merged->forceFill(['merged_into_user_id' => $target->id])->save();

        $clientsTotal = $this->getJson('/api/admin/users?per_page=100')->assertOk()->json();
        $listCount = count(data_get($clientsTotal, 'data.items', data_get($clientsTotal, 'data', [])));
        $customers = $this->getJson('/api/admin/reports/customers?period=all')->assertOk()->json('data.kpis');

        $expected = User::query()->customers()->count();
        $this->assertSame(3, $expected); // العميل الأساسي + الزائر صاحب الطلب + العميل المسجّل
        $this->assertSame($expected, $customers['total']);
        $this->assertSame($expected, $customers['new']);
        $this->assertSame($expected, $listCount);
    }

    public function test_employee_without_orders_has_null_commitment(): void
    {
        $this->employee('admin');
        $idle = $this->employee('receiver', false);

        $kpi = $this->getJson('/api/admin/employees/'.$idle->id.'/kpis?period=all')->assertOk()->json('data');
        $sla = data_get($kpi, 'receive_sla.percent', data_get($kpi, 'kpis.receive_sla.percent', 'missing'));
        $this->assertNull($sla);

        $perf = $this->getJson('/api/admin/reports/performance?period=all')->assertOk()->json('data');
        $this->assertNull($perf['operational_metrics']['sla_percent']);
    }
}
