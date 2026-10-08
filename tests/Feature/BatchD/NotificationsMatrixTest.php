<?php

namespace Tests\Feature\BatchD;

use App\Models\NotificationDispatch;
use App\Models\Offer;
use App\Services\CustomerNotificationService;

/**
 * دفعة (د) — ب10: مصفوفة الإشعارات — كل إجراء من اللوحة يرسل إشعاراً مخزّناً ويظهر في «الإشعارات المرسلة».
 */
class NotificationsMatrixTest extends BatchDTestCase
{
    public function test_receive_and_status_changes_notify_with_case_specific_bodies(): void
    {
        $this->employee('manager');
        $user = $this->customer();
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')], $user);

        $this->postJson('/api/admin/received-contracts', ['contract_id' => $contract->id])->assertOk();
        $this->postJson('/api/admin/orders/'.$contract->id.'/status', ['status_id' => $this->statusId('on_hold')])->assertOk();
        $this->postJson('/api/admin/orders/'.$contract->id.'/status', ['status_id' => $this->statusId('cancelled')])->assertOk();

        $bodies = Offer::query()->where('user_id', $user->id)->orderBy('id')->pluck('body')->all();
        $this->assertStringContainsString('استلم موظفنا طلبك', $bodies[0]);
        $this->assertStringContainsString('معلق', $bodies[1]);
        $this->assertStringContainsString('تم إلغاء طلبك', $bodies[2]);

        $sent = $this->getJson('/api/admin/orders/'.$contract->id)->assertOk()->json('data.notifications_sent');
        $this->assertCount(3, $sent);
        $this->assertSame('status_changed', $sent[0]['kind']);
        $this->assertSame('push', $sent[0]['channel']);
        $this->assertContains('inbox', $sent[0]['channels']);
        $this->assertSame('no_token', $sent[0]['push_result']);
        $this->assertNotNull($sent[0]['sent_at']);
    }

    public function test_data_missing_notification_has_deep_link_step(): void
    {
        $this->employee('manager');
        $user = $this->customer();
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee')], $user);

        $res = $this->postJson('/api/admin/orders/'.$contract->id.'/notify', ['kind' => 'data_missing', 'message' => 'صورة الصك غير واضحة', 'step' => 1])
            ->assertOk()->json('data');
        $this->assertTrue($res['stored']);

        $offer = Offer::query()->where('user_id', $user->id)->where('kind', 'data_missing')->firstOrFail();
        $this->assertStringContainsString('صورة الصك غير واضحة', $offer->body);
        $this->assertSame(1, $offer->data['step']);
        $this->assertStringContainsString('?step=1', $offer->data['deep_link']);
        $this->assertSame('data_missing', $res['notifications_sent'][0]['kind']);
    }

    public function test_discount_applied_notifies_and_is_exposed_in_detail(): void
    {
        $employee = $this->employee('admin');
        $user = $this->customer();
        $contract = $this->contract(['contract_status_id' => 1], $user);

        $this->postJson('/api/admin/users/'.$user->id.'/discount', [
            'contract_id' => $contract->id, 'type' => 'fixed', 'value' => 50, 'reason' => 'تعويض تأخير',
        ])->assertSuccessful();

        $this->assertTrue(Offer::query()->where('user_id', $user->id)->where('kind', 'discount_applied')->exists());
        $detail = $this->getJson('/api/admin/orders/'.$contract->id)->assertOk()->json('data');
        $this->assertSame('custom_discount', $detail['applied_discount']['source']);
        $this->assertSame(50.0, (float) $detail['applied_discount']['amount']);
        $this->assertNotEmpty($detail['applied_discount']['coupon_code']);
        $this->assertSame($employee->name, $detail['applied_discount']['employee_name']);
        $this->assertContains('discount_applied', array_column($detail['activities'], 'action'));
    }

    public function test_broadcast_segments_and_coupon(): void
    {
        $this->employee('admin');
        $withContract = $this->customer('0551000001');
        $this->paidContract(['contract_status_id' => $this->statusId('whatsapp_draft'), 'property_city_id' => 7], $withContract);
        $refundedOnly = $this->customer('0551000002');
        $this->paidContract(['contract_status_id' => (int) \App\Models\ContractStatus::refundedId()], $refundedOnly);
        $this->customer('0551000003'); // بلا طلبات

        $preview = $this->postJson('/api/admin/notifications/broadcast/preview', ['segment' => 'has_active_contract'])->assertOk()->json('data');
        $this->assertSame(1, $preview['recipients_count']);
        $this->assertSame(3, $this->postJson('/api/admin/notifications/broadcast/preview', ['segment' => 'all'])->json('data.recipients_count'));
        $this->assertSame(1, $this->postJson('/api/admin/notifications/broadcast/preview', ['segment' => 'city', 'city_id' => 7])->json('data.recipients_count'));
        $this->postJson('/api/admin/notifications/broadcast/preview', ['segment' => 'city'])->assertStatus(422);

        $this->postJson('/api/admin/notifications/all-users', [
            'title' => 'عرض خاص', 'body' => 'خصم 20% على التجديد', 'kind' => 'offer',
            'segment' => 'has_active_contract', 'coupon_code' => 'RENEW20', 'valid_until' => '2026-12-31',
        ])->assertOk()->assertJsonPath('data.result.recipients', 1);

        $offer = Offer::query()->where('kind', 'offer')->firstOrFail();
        $this->assertSame($withContract->id, (int) $offer->user_id);
        $data = is_array($offer->data) ? $offer->data : json_decode((string) $offer->data, true);
        $this->assertSame('RENEW20', $data['coupon_code']);
        $this->assertSame('2026-12-31', $data['valid_until']);
        $this->assertTrue(NotificationDispatch::query()->whereNull('user_id')->where('kind', 'offer')->exists());
    }

    public function test_kinds_catalog_lists_new_kinds(): void
    {
        foreach (['assigned', 'data_missing', 'refund', 'discount_applied'] as $kind) {
            $this->assertContains($kind, CustomerNotificationService::KINDS);
            $this->assertArrayHasKey($kind, CustomerNotificationService::KIND_LABELS);
        }
    }
}
