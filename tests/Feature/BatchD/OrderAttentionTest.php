<?php

namespace Tests\Feature\BatchD;

use App\Models\ContractActivity;
use App\Models\ContractStatusHistory;
use App\Models\ReceivedContract;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * دفعة (د) — ب11 / دفعة (هـ) — E3: «عليك الحين» + علامات التأخير (ساعتان / 24 ساعة بلا توثيق) + orders:flag-delays.
 * (مرحلة المسودة أُلغيت: المستلم ينتقل مباشرة إلى «بانتظار التوثيق».)
 */
class OrderAttentionTest extends BatchDTestCase
{
    public function test_board_buckets_and_delay_rules(): void
    {
        $employee = $this->employee('manager');

        $freshPaid = $this->paidContract(['contract_status_id' => $this->statusId('under_review')]);
        $this->payment($freshPaid);

        $latePaid = $this->paidContract(['contract_status_id' => $this->statusId('under_review')]);
        $p = $this->payment($latePaid, 249, 'pay_late');
        DB::table('payments')->where('id', $p->id)->update(['created_at' => now()->subHours(3)]);

        $receivedLate = $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee')]);
        $rc = ReceivedContract::query()->create(['contract_id' => $receivedLate->id, 'employee_id' => $employee->id, 'status' => 'finish', 'date_of_received' => now()->toDateString()]);
        DB::table('received_contracts')->where('id', $rc->id)->update(['created_at' => now()->subHours(30)]);

        $receivedFresh = $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee')]);
        ReceivedContract::query()->create(['contract_id' => $receivedFresh->id, 'employee_id' => $employee->id, 'status' => 'finish', 'date_of_received' => now()->toDateString()]);

        $done = $this->paidContract(['contract_status_id' => $this->statusId('ejar_authenticated')]);
        $unpaid = $this->contract();

        $board = $this->getJson('/api/admin/orders/attention')->assertOk()->json('data');

        $this->assertSame(2, $board['counts']['awaiting_receive']);
        $this->assertSame($latePaid->id, $board['awaiting_receive'][0]['id']); // الأقدم أولاً
        $this->assertSame(['paid_not_received'], $board['awaiting_receive'][0]['delay_flags']);
        $this->assertSame([], $board['awaiting_receive'][1]['delay_flags']);
        $this->assertArrayNotHasKey('awaiting_draft', $board);
        $this->assertSame(2, $board['counts']['awaiting_notarize']);
        $this->assertSame($receivedLate->id, $board['awaiting_notarize'][0]['id']); // الأقدم أولاً
        $this->assertSame(['received_not_notarized'], $board['awaiting_notarize'][0]['delay_flags']);
        $this->assertSame($employee->name, $board['awaiting_notarize'][0]['employee_name']);
        $this->assertSame([], $board['awaiting_notarize'][1]['delay_flags']);
        $this->assertSame(2, $board['counts']['delayed']);
        $this->assertSame(0, $board['counts']['awaiting_customer']);
        $ids = collect($board)->only(['awaiting_receive', 'awaiting_notarize'])->flatten(1)->pluck('id')->all();
        $this->assertNotContains($done->id, $ids);
        $this->assertNotContains($unpaid->id, $ids);

        // المجدول يحفظ العلامات ويسجّل النشاط مرة واحدة لكل تأخير جديد.
        Artisan::call('orders:flag-delays');
        $this->assertSame(['paid_not_received'], $latePaid->fresh()->delay_flags);
        $this->assertSame(2, ContractActivity::query()->where('action', 'delay_flagged')->count());
        Artisan::call('orders:flag-delays');
        $this->assertSame(2, ContractActivity::query()->where('action', 'delay_flagged')->count());

        $list = collect($this->getJson('/api/admin/orders?per_page=100')->json('data.items'))->keyBy('id');
        $this->assertTrue($list[$latePaid->id]['is_delayed']);
        $this->assertSame(['paid_not_received'], $list[$latePaid->id]['delay_flags']);
        $this->assertSame(['received_not_notarized'], $this->getJson('/api/admin/orders/'.$receivedLate->id)->json('data.delay_flags'));

        // بعد الاستلام تختفي علامة «لم يُستلم».
        $this->postJson('/api/admin/orders/'.$latePaid->id.'/stage/received')->assertOk();
        Artisan::call('orders:flag-delays');
        $this->assertNull($latePaid->fresh()->delay_flags);
    }
}
