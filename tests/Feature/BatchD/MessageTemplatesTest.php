<?php

namespace Tests\Feature\BatchD;

use App\Models\MessageTemplate;
use App\Models\Offer;
use App\Services\MessageTemplateService;

/**
 * دفعة (د) — ب16: قوالب الرسائل — زرع افتراضي + CRUD + معاينة + استخدامها في الإشعارات.
 */
class MessageTemplatesTest extends BatchDTestCase
{
    public function test_defaults_are_seeded_and_crud_works(): void
    {
        $this->employee('admin');

        $list = $this->getJson('/api/admin/message-templates')->assertOk()->json('data');
        $keys = collect($list['items'])->map(fn ($r) => $r['channel'].':'.$r['key'])->all();
        foreach (['whatsapp:stage_received', 'whatsapp:stage_draft_sent', 'whatsapp:stage_notarized', 'push:status_on_hold', 'push:refund'] as $k) {
            $this->assertContains($k, $keys);
        }
        $this->assertContains('{order}', array_column($list['placeholders'], 'token'));

        $id = $this->postJson('/api/admin/message-templates', ['key' => 'custom_hello', 'channel' => 'sms', 'body' => 'أهلاً {name} طلبك {order}'])
            ->assertStatus(201)->json('data.id');
        $this->postJson('/api/admin/message-templates', ['key' => 'custom_hello', 'channel' => 'sms', 'body' => 'x'])->assertStatus(422);
        $this->postJson('/api/admin/message-templates/'.$id, ['body' => 'مرحباً {name}'])->assertOk()->assertJsonPath('data.body', 'مرحباً {name}');
        $this->postJson('/api/admin/message-templates/'.$id.'/delete')->assertOk();
        $this->assertNull(MessageTemplate::query()->find($id));

        $this->postJson('/api/admin/message-templates/preview', ['body' => 'طلب {order} لـ {name} بمبلغ {amount}'])
            ->assertOk()->assertJsonPath('data.body', 'طلب 123456 لـ محمد بمبلغ 249');
    }

    public function test_edited_push_template_is_used_for_status_notifications(): void
    {
        $this->employee('manager');
        $user = $this->customer();
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee')], $user);

        MessageTemplate::query()->where('key', 'status_on_hold')->where('channel', 'push')
            ->update(['title' => 'طلبك موقوف مؤقتاً', 'body' => 'عزيزنا {name}، الطلب {order} موقوف']);

        $this->postJson('/api/admin/orders/'.$contract->id.'/status', ['status_id' => $this->statusId('on_hold')])->assertOk();

        $offer = Offer::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame('طلبك موقوف مؤقتاً', $offer->title);
        $this->assertSame('عزيزنا عميل تجريبي، الطلب '.$contract->uuid.' موقوف', $offer->body);
    }

    public function test_render_falls_back_to_defaults(): void
    {
        MessageTemplate::query()->delete();
        $rendered = app(MessageTemplateService::class)->render('stage_received', 'whatsapp', ['name' => 'سارة', 'order' => '111222', 'link' => 'L']);
        $this->assertSame('default', $rendered['source']);
        $this->assertStringContainsString('سارة', $rendered['body']);
        $this->assertStringContainsString('111222', $rendered['body']);
    }
}
