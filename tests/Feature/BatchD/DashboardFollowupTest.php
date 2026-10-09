<?php

namespace Tests\Feature\BatchD;

/**
 * متابعة دفعة (د) — ملاحظات اللوحة: اسم المستلم في التفاصيل، جوالات «نسخ إيجار» بصيغة 05، وتوحيد جوالات PATCH.
 */
class DashboardFollowupTest extends BatchDTestCase
{
    public function test_receiver_name_in_detail_after_stage_receive(): void
    {
        $employee = $this->employee('manager');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')]);
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/received')->assertOk();

        $data = $this->getJson('/api/admin/orders/'.$contract->id)->assertOk()->json('data');
        $this->assertSame($employee->name, $data['employee_name']);
        $this->assertSame($employee->id, $data['employee_id']);
        $this->assertSame($employee->name, $data['received_contract']['employee_name']);
        $this->assertSame($employee->name, $data['contract_summary']['employee_name']);
    }

    public function test_ejar_copy_mobiles_have_leading_zero_and_patch_normalizes(): void
    {
        $this->employee('manager');
        $contract = $this->paidContract(['tenant_mobile' => '559876543', 'property_owner_mobile' => '551234567', 'name_owner' => 'م']);

        $text = $this->getJson('/api/admin/orders/'.$contract->id.'/ejar-copy')->assertOk()->json('data.text');
        $this->assertStringContainsString('جوال المالك: 0551234567', $text);
        $this->assertStringContainsString('جوال المستأجر: 0559876543', $text);

        $this->patchJson('/api/admin/orders/'.$contract->id, ['tenant_mobile' => '0555123456'])->assertOk();
        $this->assertSame('555123456', $contract->fresh()->tenant_mobile);
        $this->patchJson('/api/admin/orders/'.$contract->id, ['property_owner_mobile' => '+966 55 000 1111'])->assertStatus(422);
        $this->patchJson('/api/admin/orders/'.$contract->id, ['property_owner_mobile' => '966550001111'])->assertOk();
        $this->assertSame('550001111', $contract->fresh()->property_owner_mobile);
    }
}
