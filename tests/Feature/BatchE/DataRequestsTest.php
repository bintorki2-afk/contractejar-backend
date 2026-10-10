<?php

namespace Tests\Feature\BatchE;

use App\Models\ContractActivity;
use App\Models\ContractDataRequest;
use App\Models\EmployeeNotification;
use App\Models\NotificationDispatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * دفعة (هـ) — 2.4 / E4: طلب مرفق ناقص متتبّع: إنشاء → واتساب/إشعار → العميل يصحّح من الرابط → حل تلقائي → إشعار الموظف
 * + «عليك الحين» بعد 24 ساعة + تنبيه المالك بعد 72 ساعة مرة واحدة.
 */
class DataRequestsTest extends BatchETestCase
{
    public function test_catalogue_matches_spec(): void
    {
        $this->employee('manager');
        $cat = $this->getJson('/api/admin/data-requests/catalogue')->assertOk()->json('data');
        $sections = collect($cat['sections'])->keyBy('key');
        $this->assertSame(['lessor', 'property', 'tenant'], $sections->keys()->all());
        $this->assertSame(['هوية الناظر/المالك غير واضحة', 'رقم الهوية خطأ', 'تاريخ الميلاد', 'الجوال', 'شهادة الوقف', 'صك النظارة', 'الوكالة'], array_column($sections['lessor']['items'], 'label'));
        $this->assertSame(['صورة الصك غير واضحة', 'رقم الصك', 'تاريخ الصك', 'نوع المستند', 'صورة العنوان الوطني', 'العنوان غير مطابق'], array_column($sections['property']['items'], 'label'));
        $this->assertSame(['هوية المستأجر غير واضحة', 'رقم الهوية', 'تاريخ الميلاد', 'الجوال'], array_column($sections['tenant']['items'], 'label'));
        $this->assertSame(24, $cat['reminder_after_hours']);
        $this->assertSame(72, $cat['owner_alert_after_hours']);
    }

    public function test_lifecycle_create_remind_resolve_cancel(): void
    {
        $employee = $this->employee('manager');
        $user = $this->customer('0551234567');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee')], $user);
        $this->payFull($contract);

        $this->postJson('/api/admin/orders/'.$contract->id.'/data-requests', ['section' => 'other', 'items' => ['x']])->assertStatus(422);
        $this->postJson('/api/admin/orders/'.$contract->id.'/data-requests', ['section' => 'property', 'items' => ['nope']])->assertStatus(422);

        $res = $this->postJson('/api/admin/orders/'.$contract->id.'/data-requests', [
            'section' => 'property', 'items' => ['deed_image_unclear', 'deed_number'], 'note' => 'الصورة مقصوصة',
        ])->assertStatus(201)->json('data');
        $rid = $res['request']['id'];
        $this->assertSame('pending', $res['request']['status']);
        $this->assertSame('بانتظار العميل · صورة الصك غير واضحة، رقم الصك', $res['request']['label']);
        $this->assertSame('https://contractejar.com/r/'.$contract->uuid.'?fix='.$rid.'&step=1', $res['request']['deep_link']);
        $this->assertStringStartsWith('https://wa.me/966551234567?text=', $res['whatsapp_url']);
        $this->assertStringContainsString('• صورة الصك غير واضحة', $res['message']);
        $this->assertStringContainsString('• الصورة مقصوصة', $res['message']);
        $this->assertStringContainsString('?fix='.$rid, $res['message']);
        $this->assertSame((string) $contract->uuid, (string) $contract->uuid);

        $push = NotificationDispatch::query()->where('contract_id', $contract->id)->where('kind', 'data_missing')->where('channel', 'push')->firstOrFail();
        $this->assertStringContainsString('صورة الصك غير واضحة', $push->body);
        $offer = \App\Models\Offer::query()->where('contract_id', $contract->id)->where('kind', 'data_missing')->firstOrFail();
        $this->assertSame($res['request']['deep_link'], $offer->url);
        $this->assertSame($rid, (int) $offer->data['request_id']);

        // الشارة في التفاصيل والقائمة.
        $detail = $this->getJson('/api/admin/orders/'.$contract->id)->json('data');
        $this->assertSame($rid, $detail['data_request_pending']['request_id']);
        $this->assertSame(['صورة الصك غير واضحة', 'رقم الصك'], $detail['data_request_pending']['items']);
        $row = collect($this->getJson('/api/admin/orders?attention=awaiting_customer')->json('data.items'))->firstWhere('id', $contract->id);
        $this->assertSame('بانتظار العميل · صورة الصك غير واضحة، رقم الصك', $row['data_request_pending']['label']);

        // التوثيق تحذير لا قفل.
        $stages = $this->getJson('/api/admin/orders/'.$contract->id.'/stages')->json('data');
        $this->assertFalse($stages['next_stage_locked']);
        $this->assertSame('data_request_pending', $stages['warnings'][0]['code']);

        // طلب جديد لنفس القسم يستبدل السابق.
        $rid2 = $this->postJson('/api/admin/orders/'.$contract->id.'/data-requests', ['section' => 'property', 'items' => ['deed_date']])->json('data.request.id');
        $this->assertSame('cancelled', ContractDataRequest::query()->find($rid)->status);
        $this->assertSame(1, ContractDataRequest::query()->where('contract_id', $contract->id)->where('status', 'pending')->count());

        // تذكير.
        $remind = $this->postJson('/api/admin/orders/'.$contract->id.'/data-requests/'.$rid2.'/remind')->assertOk()->json('data');
        $this->assertNotNull($remind['request']['reminded_at']);
        $this->assertStringContainsString('تذكير', $remind['message']);

        // حل يدوي + إلغاء.
        $this->postJson('/api/admin/orders/'.$contract->id.'/data-requests/'.$rid2.'/resolve', ['note' => 'أرسلها واتساب'])->assertOk()
            ->assertJsonPath('data.request.status', 'resolved')->assertJsonPath('data.request.resolved_by', (string) $employee->id)
            ->assertJsonPath('data.data_request_pending', null);
        $this->postJson('/api/admin/orders/'.$contract->id.'/data-requests/'.$rid2.'/resolve')->assertStatus(422);
        $rid3 = $this->postJson('/api/admin/orders/'.$contract->id.'/data-requests', ['section' => 'tenant', 'items' => ['tenant_mobile']])->json('data.request.id');
        $this->postJson('/api/admin/orders/'.$contract->id.'/data-requests/'.$rid3.'/cancel')->assertOk()->assertJsonPath('data.request.status', 'cancelled');
        $this->assertCount(3, $this->getJson('/api/admin/orders/'.$contract->id.'/data-requests')->json('data.items'));
        $actions = ContractActivity::query()->where('contract_id', $contract->id)->pluck('action')->all();
        foreach (['data_request_sent', 'data_request_reminded', 'data_request_resolved', 'data_request_cancelled'] as $a) {
            $this->assertContains($a, $actions);
        }
    }

    public function test_customer_fix_auto_resolves_and_notifies_employee(): void
    {
        foreach (['ReaEstatTypeSeeder', 'ReaEstatUsageSeeder'] as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }
        $employee = $this->employee('manager');
        $user = $this->customer('0551234567');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review'), 'property_type_id' => 1, 'property_usages_id' => 1], $user);
        $this->payFull($contract);
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/received')->assertOk();
        $rid = $this->postJson('/api/admin/orders/'.$contract->id.'/data-requests', ['section' => 'property', 'items' => ['deed_number']])->json('data.request.id');

        Sanctum::actingAs($user, ['*']);
        $me = $this->getJson('/api/v2/contracts/'.$contract->id)->assertOk()->json('data');
        $this->assertSame($rid, $me['pending_data_requests'][0]['id']);
        $this->assertSame(1, $me['pending_data_requests'][0]['step']);
        $this->assertSame('مطلوب منك: رقم الصك', $me['pending_data_requests'][0]['banner']);

        // خطوة أخرى (المستأجر) مقفلة لأن الطلب مدفوع ولا طلب مرفق لها.
        $this->postJson('/api/v2/contract/step3', ['id' => $contract->id, 'name_owner' => 'x', 'property_owner_id_num' => '1023456789', 'property_owner_dob_day' => 10, 'property_owner_dob_month' => 5, 'property_owner_dob_year' => 1400, 'type_dob_property_owner' => 'hijri', 'property_owner_mobile' => '559876543'])
            ->assertStatus(400);

        $step1 = ['id' => $contract->id, 'instrument_type' => 'electronic', 'instrument_number' => '440999888777', 'instrument_history' => '10-05-1440', 'type_instrument_history' => 'hijri',
            'property_type_id' => 1, 'property_usages_id' => 1, 'number_of_floors' => 2, 'number_of_units_in_realestate' => '4'];
        $fix = $this->postJson('/api/v2/contract/step1', $step1)->assertOk()->json('fix');
        $this->assertTrue($fix['fix_mode']);
        $this->assertContains('instrument_number', $fix['changed_fields']);
        $this->assertSame([$rid], $fix['resolved_request_ids']);
        $this->assertSame([], $fix['pending_data_requests']);
        $this->assertSame('تم الإرسال — سيراجعها الموظف.', $fix['message']);

        $fresh = $contract->fresh();
        $this->assertSame(7, (int) $fresh->step);
        $this->assertTrue((bool) $fresh->is_completed);
        $this->assertSame('440999888777', $fresh->instrument_number);
        $req = ContractDataRequest::query()->find($rid);
        $this->assertSame('resolved', $req->status);
        $this->assertSame('customer', $req->resolved_by);
        $this->assertSame(['instrument_number'], $req->resolved_fields);

        $activity = ContractActivity::query()->where('contract_id', $contract->id)->where('action', 'data_request_resolved')->firstOrFail();
        $this->assertSame('customer', $activity->actor_type);
        $this->assertSame('العميل أرسل: رقم الصك', $activity->note);
        $this->assertTrue((bool) $activity->customer_visible);

        $notif = EmployeeNotification::query()->where('contract_id', $contract->id)->where('kind', 'data_request_resolved')->firstOrFail();
        $this->assertSame($employee->id, (int) $notif->employee_id);

        // الطلب مقفل من جديد بعد الحل.
        $this->postJson('/api/v2/contract/step1', array_merge($step1, ['instrument_number' => '1']))->assertStatus(422);

        // الموظف يرى الإشعار ويعلّمه مقروءاً.
        Sanctum::actingAs($employee);
        $list = $this->getJson('/api/admin/employee-notifications?unread=1')->assertOk()->json('data');
        $this->assertSame(1, $list['unread_count']);
        $this->assertSame('data_request_resolved', $list['items'][0]['kind']);
        $this->postJson('/api/admin/employee-notifications/'.$list['items'][0]['id'].'/read')->assertOk()->assertJsonPath('data.is_read', true);
        $this->assertSame(0, $this->getJson('/api/admin/employee-notifications')->json('data.unread_count'));
        $this->assertNull($this->getJson('/api/admin/orders/'.$contract->id)->json('data.data_request_pending'));
    }

    public function test_attention_after_24h_and_owner_alert_after_72h_once(): void
    {
        config(['services.telegram.bot_token' => 'bot-test', 'services.telegram.chat_id' => '1']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->employee('manager');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee')]);
        $this->payFull($contract);
        $rid = $this->postJson('/api/admin/orders/'.$contract->id.'/data-requests', ['section' => 'tenant', 'items' => ['tenant_id_unclear']])->json('data.request.id');

        $board = $this->getJson('/api/admin/orders/attention')->json('data');
        $this->assertSame(0, $board['counts']['awaiting_customer']);

        // السفر في الزمن: 30 ساعة.
        DB::table('contract_data_requests')->where('id', $rid)->update(['requested_at' => Carbon::now()->subHours(30), 'created_at' => Carbon::now()->subHours(30)]);
        $board = $this->getJson('/api/admin/orders/attention')->json('data');
        $this->assertSame(1, $board['counts']['awaiting_customer']);
        $item = $board['awaiting_customer']['items'][0];
        $this->assertSame((string) $contract->uuid, $item['uuid']);
        $this->assertSame(['هوية المستأجر غير واضحة'], $item['items']);
        $this->assertSame(30, $item['hours_waiting']);
        $this->assertStringStartsWith('https://wa.me/966551234567?text=', $item['whatsapp_url']);
        $this->assertContains($contract->id, array_column($board['delayed'], 'id'));
        $row = collect($this->getJson('/api/admin/orders?per_page=100')->json('data.items'))->firstWhere('id', $contract->id);
        $this->assertContains('customer_no_reply_24h', $row['delay_flags']);
        $this->assertContains('customer_no_reply_24h', $this->getJson('/api/admin/orders/'.$contract->id)->json('data.delay_flags'));

        Artisan::call('orders:flag-delays');
        Http::assertNothingSent();
        $this->assertNull(ContractDataRequest::query()->find($rid)->owner_alerted_at);

        // 80 ساعة ⇒ تنبيه تيليجرام للمالك مرة واحدة فقط.
        DB::table('contract_data_requests')->where('id', $rid)->update(['requested_at' => Carbon::now()->subHours(80)]);
        Artisan::call('orders:flag-delays');
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.telegram.org') && str_contains($r['text'], (string) $contract->uuid) && str_contains($r['text'], 'هوية المستأجر غير واضحة'));
        $this->assertNotNull(ContractDataRequest::query()->find($rid)->owner_alerted_at);
        Artisan::call('orders:flag-delays');
        Http::assertSentCount(1);
        $this->assertContains('customer_no_reply_72h', $this->getJson('/api/admin/orders/'.$contract->id)->json('data.delay_flags'));

        // التقرير الأسبوعي يذكر الطلبات المفتوحة.
        $text = app(\App\Services\Admin\WeeklyOwnerReportService::class)->text(app(\App\Services\Admin\WeeklyOwnerReportService::class)->build());
        $this->assertStringContainsString('طلبات مرفق ناقص مفتوحة: 1', $text);
        $this->assertStringContainsString('بلا رد +24 ساعة', $text);
    }
}
