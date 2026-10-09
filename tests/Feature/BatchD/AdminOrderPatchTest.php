<?php

namespace Tests\Feature\BatchD;

use App\Models\ContractActivity;

/**
 * دفعة (د) — ب17: PATCH /api/admin/orders/{id} للحقول الصغيرة مع التحقق والتدقيق.
 */
class AdminOrderPatchTest extends BatchDTestCase
{
    public function test_patch_validates_saves_and_audits(): void
    {
        $employee = $this->employee('manager');
        $contract = $this->paidContract(['tenant_id_num' => '1098765432', 'annual_rent_amount_for_the_unit' => 20000]);

        $res = $this->patchJson('/api/admin/orders/'.$contract->id, ['tenant_id_num' => '١٠١١١٢٢٢٣٣', 'annual_rent_amount_for_the_unit' => 25000])
            ->assertOk()->json('data');
        $this->assertSame('1098765432', $res['changed']['tenant_id_num']['before']);
        $this->assertSame('1011122233', $res['changed']['tenant_id_num']['after']);
        $this->assertSame('1011122233', $contract->fresh()->tenant_id_num);

        $act = ContractActivity::query()->where('contract_id', $contract->id)->where('action', 'edited')->latest('id')->firstOrFail();
        $this->assertSame($employee->id, (int) $act->actor_id);
        $this->assertSame('1011122233', $act->after['tenant_id_num']);
        $this->assertStringContainsString('رقم هوية المستأجر', $act->note);

        $this->patchJson('/api/admin/orders/'.$contract->id, ['tenant_id_num' => '12345'])->assertStatus(422);
        $this->patchJson('/api/admin/orders/'.$contract->id, ['property_owner_iban' => 'SA12'])->assertStatus(422);
        $this->patchJson('/api/admin/orders/'.$contract->id, ['is_completed' => 0])->assertStatus(422);
        $this->patchJson('/api/admin/orders/'.$contract->id, ['tenant_id_num' => '1011122233'])->assertOk()->assertJsonPath('data.changed', []);
        $this->assertNotEmpty($this->getJson('/api/admin/orders/editable-fields')->assertOk()->json('data'));
    }
}
