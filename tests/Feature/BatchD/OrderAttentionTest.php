<?php

namespace Tests\Feature\BatchD;

use App\Models\ContractActivity;
use App\Models\ContractStatusHistory;
use App\Models\ReceivedContract;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * دفعة (د) — ب11: «عليك الحين» + علامات التأخير (ساعتان / 24 / 72 ساعة) + orders:flag-delays.
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

        $draftLate = $this->paidContract(['contract_status_id' => $this->statusId('whatsapp_draft')]);
        ReceivedContract::query()->create(['contract_id' => $draftLate->id, 'employee_id' => $employee->id, 'status' => 'finish', 'date_of_received' => now()->toDateString()]);
        $h = ContractStatusHistory::query()->create(['contract_id' => $draftLate->id, 'status_type' => 'contract', 'status' => 'whatsapp_draft', 'status_label' => 'مسودة', 'source' => 'admin']);
        DB::table('contract_status_histories')->where('id', $h->id)->update(['created_at' => now()->subHours(80)]);

        $done = $this->paidContract(['contract_status_id' => $this->statusId('ejar_authenticated')]);
        $unpaid = $this->contract();

        $board = $this->getJson('/api/admin/orders/attention')->assertOk()->json('data');

        $this->assertSame(2, $board['counts']['awaiting_receive']);
        $this->assertSame($latePaid->id, $board['awaiting_receive'][0]['id']); // الأقدم أولاً
        $this->assertSame(['paid_not_received'], $board['awaiting_receive'][0]['delay_flags']);
        $this->assertSame([], $board['awaiting_receive'][1]['delay_flags']);
        $this->assertSame(1, $board['counts']['awaiting_draft']);
        $this->assertSame(['received_no_draft'], $board['awaiting_draft'][0]['delay_flags']);
        $this->assertSame($employee->name, $board['awaiting_draft'][0]['employee_name']);
        $this->assertSame(1, $board['counts']['awaiting_notarize']);
        $this->assertSame(['draft_no_notarize'], $board['awaiting_notarize'][0]['delay_flags']);
        $this->assertSame(3, $board['counts']['delayed']);
        $ids = collect($board)->only(['awaiting_receive', 'awaiting_draft', 'awaiting_notarize'])->flatten(1)->pluck('id')->all();
        $this->assertNotContains($done->id, $ids);
        $this->assertNotContains($unpaid->id, $ids);

        // المجدول يحفظ العلامات ويسجّل النشاط مرة واحدة لكل تأخير جديد.
        Artisan::call('orders:flag-delays');
        $this->assertSame(['paid_not_received'], $latePaid->fresh()->delay_flags);
        $this->assertSame(3, ContractActivity::query()->where('action', 'delay_flagged')->count());
        Artisan::call('orders:flag-delays');
        $this->assertSame(3, ContractActivity::query()->where('action', 'delay_flagged')->count());

        $list = collect($this->getJson('/api/admin/orders?per_page=100')->json('data.items'))->keyBy('id');
        $this->assertTrue($list[$latePaid->id]['is_delayed']);
        $this->assertSame(['paid_not_received'], $list[$latePaid->id]['delay_flags']);
        $this->assertSame(['draft_no_notarize'], $this->getJson('/api/admin/orders/'.$draftLate->id)->json('data.delay_flags'));

        // بعد الاستلام تختفي علامة «لم يُستلم».
        $this->postJson('/api/admin/orders/'.$latePaid->id.'/stage/received')->assertOk();
        Artisan::call('orders:flag-delays');
        $this->assertNull($latePaid->fresh()->delay_flags);
    }
}
