<?php

namespace Tests\Feature\BatchD;

use App\Models\Contract;
use App\Models\LessorChangeRequest;
use App\Models\Payment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * دفعة (د) — ب12: الحذف ينقل للسلة، الاستعادة خلال 30 يوماً، والحذف النهائي بعدها؛ الدفعات لا تُحذف.
 */
class TrashTest extends BatchDTestCase
{
    public function test_delete_moves_to_trash_and_restore_brings_it_back(): void
    {
        $employee = $this->employee('admin');
        $contract = $this->contract(['contract_status_id' => 1]);

        $res = $this->deleteJson('/api/admin/orders/'.$contract->id)->assertOk()->json('data');
        $this->assertTrue($res['restorable']);
        $this->assertSame(30, $res['days_left']);
        $this->assertSame($employee->name, $res['deleted_by_name']);

        $this->assertNotContains($contract->id, collect($this->getJson('/api/admin/orders?per_page=100')->json('data.items'))->pluck('id')->all());
        $trash = $this->getJson('/api/admin/orders/trash')->assertOk()->json('data.items');
        $this->assertSame($contract->id, $trash[0]['id']);
        $this->assertArrayHasKey('purge_at', $trash[0]);

        $this->postJson('/api/admin/orders/'.$contract->id.'/restore')->assertOk();
        $this->assertSame(0, (int) $contract->fresh()->is_delete);
        $this->assertContains($contract->id, collect($this->getJson('/api/admin/orders?per_page=100')->json('data.items'))->pluck('id')->all());
        $this->assertEqualsCanonicalizing(['deleted', 'restored'], \App\Models\ContractActivity::query()->where('contract_id', $contract->id)->pluck('action')->all());

        // الحذف القديم (POST /delete) يمر بنفس السلة.
        $this->postJson('/api/admin/orders/'.$contract->id.'/delete')->assertOk();
        $this->assertNotNull($contract->fresh()->trashed_at);
    }

    public function test_paid_order_needs_admin_force_and_payments_survive(): void
    {
        $this->employee('manager');
        $paid = $this->paidContract();
        $this->payment($paid);
        $this->deleteJson('/api/admin/orders/'.$paid->id)->assertStatus(422);

        $this->employee('admin');
        $this->deleteJson('/api/admin/orders/'.$paid->id.'?force=1')->assertOk();
        $this->assertTrue(Payment::query()->where('contract_uuid', (string) $paid->uuid)->exists());
    }

    public function test_purge_after_30_days_and_no_restore_after_expiry(): void
    {
        $this->employee('admin');
        $old = $this->contract();
        $recent = $this->contract();
        $this->deleteJson('/api/admin/orders/'.$old->id)->assertOk();
        $this->deleteJson('/api/admin/orders/'.$recent->id)->assertOk();
        DB::table('contracts')->where('id', $old->id)->update(['trashed_at' => now()->subDays(31)]);

        $this->postJson('/api/admin/orders/'.$old->id.'/restore')->assertStatus(422);
        Artisan::call('trash:purge');
        $this->assertNull(Contract::query()->find($old->id));
        $this->assertNotNull(Contract::query()->find($recent->id));
    }

    public function test_lessor_change_trash_and_restore(): void
    {
        $this->employee('admin');
        $user = $this->customer();
        $row = LessorChangeRequest::query()->create([
            'uuid' => LessorChangeRequest::generateUuid(), 'user_id' => $user->id, 'mobile' => '966551234567',
            'old_deed_image' => 'x', 'new_deed_image' => 'y', 'new_owner_id_number' => '1098765432',
            'new_owner_dob' => '10/05/1410', 'new_owner_dob_type' => 'hijri', 'fee' => 400, 'status' => 'pending_payment', 'platform' => 'web',
        ]);

        $this->deleteJson('/api/admin/lessor-change/'.$row->id)->assertOk()->assertJsonPath('data.restorable', true);
        $this->assertSame($row->id, $this->getJson('/api/admin/lessor-change/trash')->assertOk()->json('data.items.0.id'));
        $this->assertSame([], $this->getJson('/api/admin/lessor-change')->json('data.items'));
        $this->postJson('/api/admin/lessor-change/'.$row->id.'/restore')->assertOk();
        $this->assertFalse((bool) $row->fresh()->is_delete);
    }
}
