<?php

namespace Tests\Feature\BatchD;

use App\Models\ContractActivity;
use App\Models\NotificationDispatch;

/**
 * دفعة (د) — ب14 / دفعة (هـ) — E3: نقاط المراحل «استلمت» → «وثّقت» مع رسالة واتساب من القالب (المسودة أُلغيت).
 */
class StageEndpointsTest extends BatchDTestCase
{
    public function test_full_stage_flow_returns_whatsapp_messages_and_notifies(): void
    {
        $employee = $this->employee('manager');
        $user = $this->customer('0551234567');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')], $user);
        $this->payment($contract, \App\Support\ContractPricing::total($contract));

        $stages = $this->getJson('/api/admin/orders/'.$contract->id.'/stages')->assertOk()
            ->assertJsonPath('data.next_stage', 'received')
            ->assertJsonPath('data.customer_phone', '966551234567')->json('data');
        $this->assertSame(['received', 'notarized'], array_column($stages['stages'], 'key'));
        $this->assertCount(3, $stages['journey']);

        // دفعة (هـ): مرحلة المسودة أُلغيت ⇒ 410.
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/draft_sent', ['ejar_contract_draft_number' => 'D1', 'contact_number_mode' => 'same'])
            ->assertStatus(410)->assertJsonPath('code', 410);

        $r1 = $this->postJson('/api/admin/orders/'.$contract->id.'/stage/received')->assertOk()->json('data');
        $this->assertSame('received_by_employee', $r1['contract']['status']);
        $this->assertSame('notarized', $r1['next_stage']);
        $this->assertStringStartsWith('https://wa.me/966551234567?text=', $r1['whatsapp']['url']);
        $this->assertStringContainsString((string) $contract->uuid, $r1['whatsapp']['message']);
        $this->assertSame('deed_type', $r1['next_stage_required_fields'][0]['name']);
        $this->assertTrue($r1['payment_state']['can_notarize']);

        // حقول التوثيق ناقصة ⇒ 422
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/notarized')->assertStatus(422);

        $r3 = $this->postJson('/api/admin/orders/'.$contract->id.'/stage/notarized', ['deed_number' => '4455', 'deed_type' => 'electronic'])
            ->assertOk()->json('data');
        $this->assertSame('ejar_authenticated', $r3['contract']['status']);
        $this->assertNull($r3['next_stage']);

        $actions = ContractActivity::query()->where('contract_id', $contract->id)->pluck('action')->all();
        foreach (['stage_received', 'stage_notarized', 'received'] as $a) {
            $this->assertContains($a, $actions);
        }
        $this->assertNotContains('stage_draft_sent', $actions);
        $kinds = NotificationDispatch::query()->where('contract_id', $contract->id)->get(['kind', 'channel'])
            ->map(fn ($d) => $d->channel.':'.$d->kind)->all();
        $this->assertContains('push:notarized', $kinds);
        $this->assertContains('whatsapp:stage_notarized', $kinds);
        $this->assertContains('whatsapp:stage_received', $kinds);
        $this->assertNotContains('push:draft_sent', $kinds);

        $this->getJson('/api/admin/orders/'.$contract->id.'/stages')->assertOk()->assertJsonPath('data.current_stage', 'notarized');
    }

    public function test_notarize_is_locked_until_payment_recorded(): void
    {
        $this->employee('manager');
        $contract = $this->contract(['contract_status_id' => $this->statusId('new')]);

        // الموظف يستلم طلباً غير مدفوع.
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/received')->assertOk();
        $stages = $this->getJson('/api/admin/orders/'.$contract->id.'/stages')->assertOk()->json('data');
        $this->assertTrue($stages['next_stage_locked']);
        $this->assertSame('payment_required', $stages['next_stage_lock_reason']);

        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/notarized', ['deed_number' => '1', 'deed_type' => 'electronic'])
            ->assertStatus(422)->assertJsonPath('code', 'payment_required');
    }

    public function test_another_employee_cannot_re_receive(): void
    {
        $this->employee('manager');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')]);
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/received')->assertOk();
        // نفس الموظف ⇒ idempotent
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/received')->assertOk();

        $this->employee('manager');
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/received')->assertStatus(422);
    }
}
