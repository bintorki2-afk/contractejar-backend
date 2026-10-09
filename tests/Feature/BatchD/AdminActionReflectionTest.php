<?php

namespace Tests\Feature\BatchD;

use App\Models\ContractActivity;
use Laravel\Sanctum\Sanctum;

/**
 * دفعة (د) — ب9: كل إجراء من اللوحة يظهر فوراً للعميل (مورد الطلب + التتبع) ويُسجَّل في سجل النشاط.
 */
class AdminActionReflectionTest extends BatchDTestCase
{
    public function test_status_change_is_reflected_and_logged_everywhere(): void
    {
        $employee = $this->employee('manager');
        $user = $this->customer('0551234567');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')], $user);

        $this->postJson('/api/admin/orders/'.$contract->id.'/status', ['status_id' => $this->statusId('on_hold')])->assertOk();

        // اللوحة: سجل النشاط بالمنفّذ وقبل/بعد.
        $detail = $this->getJson('/api/admin/orders/'.$contract->id)->assertOk()->json('data');
        $activity = collect($detail['activities'])->firstWhere('action', 'status_changed');
        $this->assertNotNull($activity);
        $this->assertSame($employee->id, $activity['actor_id']);
        $this->assertSame('under_review', $activity['before']['status_key']);
        $this->assertSame('on_hold', $activity['after']['status_key']);
        $this->assertSame('معلق', $activity['after']['status_name']);

        // التتبع العام: الحالة الجديدة فوراً + نشاط آمن للعميل (بلا اسم الموظف).
        $track = $this->postJson('/api/v2/contract/track', ['order' => (string) $contract->uuid, 'mobile' => '0551234567'])
            ->assertOk()->json('data');
        $this->assertSame('on_hold', $track['status']);
        $this->assertSame('معلق', collect($track['activities'])->last()['label']);
        $this->assertArrayNotHasKey('actor_name', collect($track['activities'])->last());

        // مورد العميل.
        Sanctum::actingAs($user);
        $mine = $this->getJson('/api/v2/contracts/'.$contract->id)->assertOk()->json('data');
        $this->assertSame('on_hold', $mine['status'] ?? $mine['journey_status'] ?? null);
        $this->assertNotEmpty($mine['activities']);
    }

    public function test_edit_and_note_are_audited_with_before_after(): void
    {
        $employee = $this->employee('manager');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee'), 'tenant_mobile' => '0551234567']);

        $this->postJson('/api/admin/orders/'.$contract->id, ['tenant_mobile' => '0559999999'])->assertOk();
        $this->postJson('/api/admin/orders/'.$contract->id.'/comments', ['comment' => 'العميل طلب تعديل الجوال'])->assertStatus(201);

        $edit = ContractActivity::query()->where('contract_id', $contract->id)->where('action', 'edited')->first();
        $this->assertNotNull($edit);
        $this->assertSame('0551234567', $edit->before['tenant_mobile']);
        $this->assertSame('0559999999', $edit->after['tenant_mobile']);
        $this->assertSame($employee->id, (int) $edit->actor_id);
        $this->assertFalse((bool) $edit->customer_visible);

        $note = ContractActivity::query()->where('contract_id', $contract->id)->where('action', 'note_added')->first();
        $this->assertSame('العميل طلب تعديل الجوال', $note->note);
        $this->assertFalse((bool) $note->customer_visible);
    }
}
