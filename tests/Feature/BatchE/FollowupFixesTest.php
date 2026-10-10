<?php

namespace Tests\Feature\BatchE;

use App\Models\ContractCharge;
use App\Models\Payment;
use App\Services\FirebaseNotificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * دفعة (هـ) — متابعة ملاحظات اللوحة/الموقع/التطبيق (issues-for-backend):
 * W-1/A-1 التعبئة المسبقة في وضع التصحيح، A-2 نتيجة دفع الرسم، A-3 نصوص FCM،
 * #1 صياغة قالب تذكير الدفع، #2 إجمالي إيراد الموظف.
 */
class FollowupFixesTest extends BatchETestCase
{
    private function seedLookups(): void
    {
        foreach (['ReaEstatTypeSeeder', 'ReaEstatUsageSeeder'] as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }
    }

    /** W-1 (a): طلب مدفوع له طلب مرفق ناقص معلّق ⇒ كل الخطوات + fix_mode؛ بدون طلب ⇒ 400 كما كان. */
    public function test_uncompleted_contract_returns_all_steps_in_fix_mode(): void
    {
        $this->seedLookups();
        $this->employee('manager');
        $user = $this->customer('0551234567');
        $contract = $this->paidContract([
            'contract_status_id' => $this->statusId('received_by_employee'),
            'property_type_id' => 1, 'property_usages_id' => 1, 'instrument_number' => '440111222333',
            'instrument_history' => '10-05-1440', 'type_instrument_history' => 'hijri', 'number_of_floors' => 2,
            'property_owner_id_num' => '1023456789', 'property_owner_mobile' => '559876543',
            'tenant_id_num' => '1098765432', 'tenant_mobile' => '0551234567',
        ], $user);
        $this->payFull($contract);

        Sanctum::actingAs($user, ['*']);
        // بلا طلب معلّق: السلوك القديم (400).
        $this->postJson('/api/v2/contract/uncompleted-contract', ['uuid' => (string) $contract->uuid])->assertStatus(400);

        $this->employee('manager');
        $rid = $this->postJson('/api/admin/orders/'.$contract->id.'/data-requests', ['section' => 'property', 'items' => ['deed_number']])
            ->assertStatus(201)->json('data.request.id');

        Sanctum::actingAs($user, ['*']);
        $data = $this->postJson('/api/v2/contract/uncompleted-contract', ['uuid' => (string) $contract->uuid])->assertOk()->json('data');
        $this->assertTrue($data['fix_mode']);
        $this->assertSame($contract->id, $data['contract_id']);
        $this->assertSame(7, $data['step']);
        $this->assertSame([1], $data['editable_steps']);
        $this->assertSame($rid, $data['pending_data_requests'][0]['id']);
        $this->assertSame(1, $data['pending_data_requests'][0]['step']);
        foreach (['step1', 'step2', 'step3', 'step4', 'step5', 'step6'] as $k) {
            $this->assertArrayHasKey($k, $data, $k.' missing in fix mode');
        }
        $this->assertSame('440111222333', $data['step1']['instrument_number']);
        $this->assertSame('1023456789', $data['step3']['property_owner_id_num']);
        $this->assertSame('1098765432', $data['step4']['tenant_id_num']);

        // غير صاحب الطلب لا يراه.
        Sanctum::actingAs($this->customer('0559999999'), ['*']);
        $this->postJson('/api/v2/contract/uncompleted-contract', ['uuid' => (string) $contract->uuid])->assertStatus(404);
    }

    /** W-1 (b) / A-1: مورد العقد للعميل يحمل حقول الخطوات 1/3/4 (بلا أسماء). */
    public function test_customer_contract_resource_carries_step_scalar_fields(): void
    {
        $this->seedLookups();
        $user = $this->customer('0551234567');
        $contract = $this->paidContract([
            'property_type_id' => 1, 'property_usages_id' => 1, 'instrument_number' => '440111222333',
            'instrument_history' => '10-05-1440', 'type_instrument_history' => 'hijri', 'number_of_floors' => 3,
            'name_owner' => 'سري', 'property_owner_id_num' => '1023456789', 'property_owner_dob' => '12-07-1400',
            'type_dob_property_owner' => 'hijri', 'property_owner_mobile' => '559876543',
            'tenant_name' => 'سري', 'tenant_id_num' => '1098765432', 'tenant_dob' => '01-01-1410', 'type_tenant_dob' => 'hijri',
            'neighborhood' => 'العليا', 'street' => 'الملك فهد', 'building_number' => '1234', 'postal_code' => '12211',
        ], $user);

        Sanctum::actingAs($user, ['*']);
        $me = $this->getJson('/api/v2/contracts/'.$contract->id)->assertOk()->json('data');
        $this->assertSame('440111222333', $me['instrument_number']);
        $this->assertSame('10-05-1440', $me['instrument_history']);
        $this->assertSame('hijri', $me['type_instrument_history']);
        $this->assertSame(1, (int) $me['property_type_id']);
        $this->assertSame(1, (int) $me['property_usages_id']);
        $this->assertSame(3, (int) $me['number_of_floors']);
        $this->assertSame('559876543', $me['property_owner_mobile']);
        $this->assertSame('12-07-1400', $me['property_owner_dob']);
        $this->assertSame(12, (int) $me['property_owner_dob_day']);
        $this->assertSame(7, (int) $me['property_owner_dob_month']);
        $this->assertSame(1400, (int) $me['property_owner_dob_year']);
        $this->assertSame('01-01-1410', $me['tenant_dob']);
        $this->assertSame(1410, (int) $me['tenant_dob_year']);
        $this->assertSame('العليا', $me['neighborhood']);
        $this->assertSame('1234', $me['building_number']);
        $this->assertArrayNotHasKey('name_owner', $me);
        $this->assertArrayNotHasKey('tenant_name', $me);
    }

    /** A-2: نتيجة الدفع بمفتاح رسم chg-{uuid}-{id} تعيد الطلب الأصل والدفعة والرسم لصاحب الطلب. */
    public function test_payment_result_for_charge_key_resolves_parent_contract(): void
    {
        $this->employee('admin');
        $user = $this->customer('0551234567');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')], $user);
        $this->payFull($contract);
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/received')->assertOk();
        $cid = $this->postJson('/api/admin/orders/'.$contract->id.'/charges', ['amount' => 120, 'message' => 'رسوم إضافية'])->json('data.charge.id');
        $key = Payment::chargeKey((string) $contract->uuid, $cid);

        Http::fake([
            'https://api.moyasar.com/v1/payments/pay_charge_r' => Http::response([
                'id' => 'pay_charge_r', 'status' => 'paid', 'amount' => 12000, 'currency' => 'SAR',
                'source' => ['type' => 'creditcard', 'company' => 'mada'], 'metadata' => ['contract_uuid' => $key, 'charge_id' => (string) $cid],
            ], 200),
        ]);
        $this->postJson('/api/status/'.$key.'/success', ['id' => 'pay_charge_r', 'status' => 'paid']);
        $this->assertSame('paid', ContractCharge::query()->find($cid)->status);

        // مجهول: بلا تفاصيل داخلية.
        $anon = $this->getJson('/api/v2/payment/result/'.$key)->assertOk()->json('data');
        $this->assertTrue($anon['paid']);
        $this->assertSame('charge', $anon['kind']);
        $this->assertNull($anon['contract_id']);
        $this->assertNull($anon['payment']);
        $this->assertNull($anon['charge']);

        // صاحب الطلب: الطلب الأصل + الدفعة + الرسم.
        Sanctum::actingAs($user, ['*']);
        $own = $this->getJson('/api/v2/payment/result/'.$key)->assertOk()->json('data');
        $this->assertTrue($own['paid']);
        $this->assertSame($contract->id, $own['contract_id']);
        $this->assertTrue($own['is_completed']);
        $this->assertEquals(120, $own['payment']['amount']);
        $this->assertSame('success', $own['payment']['status']);
        $this->assertSame($cid, $own['charge']['id']);
        $this->assertSame('paid', $own['charge']['status']);
        $this->assertEquals(120, $own['charge']['amount']);
    }

    /** A-3: قيم data في رسالة FCM نصوص دائماً (مصفوفة ⇒ JSON، منطقي ⇒ 1/0، null ⇒ فارغ). */
    public function test_fcm_data_values_are_always_strings(): void
    {
        $service = app(FirebaseNotificationService::class);
        $m = new \ReflectionMethod($service, 'stringifyData');
        $m->setAccessible(true);
        $out = $m->invoke($service, [
            'request_id' => 12, 'step' => 1, 'items' => ['رقم الصك', 'صورة الصك غير واضحة'],
            'flag' => true, 'off' => false, 'none' => null, 'nested' => ['a' => 1], 'deep_link' => 'https://contractejar.com/r/1?fix=12',
        ]);
        foreach ($out as $k => $v) {
            $this->assertIsString($v, $k.' is not a string');
        }
        $this->assertSame('12', $out['request_id']);
        $this->assertSame('["رقم الصك","صورة الصك غير واضحة"]', $out['items']);
        $this->assertSame('1', $out['flag']);
        $this->assertSame('0', $out['off']);
        $this->assertSame('', $out['none']);
        $this->assertSame('{"a":1}', $out['nested']);
    }

    /** #1: ترحيل صياغة قالب تذكير الدفع — يُحدَّث النص الافتراضي القديم فقط، ويُترك المعدَّل. */
    public function test_payment_reminder_template_wording_migration_only_touches_unedited(): void
    {
        $old = "مرحباً {name}\nطلبك رقم {order} جاهز للدفع ({amount} ر.س). ادفع الآن لنبدأ إعداد مسودة عقدك: {link}";
        $edited = "نص عدّله المالك بنفسه: {link}";
        DB::table('message_templates')->where('key', 'payment_reminder')->delete();
        DB::table('message_templates')->insert([
            ['key' => 'payment_reminder', 'channel' => 'whatsapp', 'body' => $old, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'payment_reminder', 'channel' => 'push', 'body' => $edited, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration = require base_path('database/migrations/2026_10_10_000400_batch_e_payment_reminder_wording.php');
        $migration->up();

        $wa = DB::table('message_templates')->where('key', 'payment_reminder')->where('channel', 'whatsapp')->value('body');
        $this->assertStringNotContainsString('مسودة', $wa);
        $this->assertStringContainsString('لنبدأ توثيق عقدك', $wa);
        $this->assertSame($edited, DB::table('message_templates')->where('key', 'payment_reminder')->where('channel', 'push')->value('body'));

        // نص معدَّل في واتساب لا يُمس.
        DB::table('message_templates')->where('key', 'payment_reminder')->where('channel', 'whatsapp')->update(['body' => $edited]);
        $migration->up();
        $this->assertSame($edited, DB::table('message_templates')->where('key', 'payment_reminder')->where('channel', 'whatsapp')->value('body'));

        // النص الافتراضي الحالي لا يذكر «مسودة».
        foreach (\App\Support\MessageTemplateDefaults::ROWS as $row) {
            $this->assertStringNotContainsString('مسودة', (string) ($row['body'] ?? ''), 'template '.$row['key']);
        }
    }

    /** #2: إيراد التوثيق + الإجمالي (توثيق + رسوم + حوالات) في مؤشرات الموظف والملخص. */
    public function test_employee_kpi_has_revenue_total_with_parts(): void
    {
        $employee = $this->employee('admin');
        $user = $this->customer('0551234567');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')], $user);
        $this->payFull($contract);
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/received')->assertOk();
        $this->postJson('/api/admin/orders/'.$contract->id.'/charges', ['amount' => 120, 'message' => 'رسوم إضافية'])->assertStatus(201);

        $other = $this->contract(['contract_status_id' => $this->statusId('under_review')], $user);
        $this->post('/api/admin/orders/'.$other->id.'/payments/bank-transfer', [
            'amount' => \App\Support\ContractPricing::total($other->fresh()), 'reference' => 'TRX-1', 'receipt' => $this->fakePng(),
        ])->assertOk();

        $kpi = $this->getJson('/api/admin/employees/'.$employee->id.'/kpis?period=today')->assertOk()->json('data');
        $this->assertSame('إيراد التوثيق', $kpi['revenue']['label_ar']);
        $this->assertSame('revenue_total_sar', $kpi['revenue_total']['key']);
        $expected = (float) ($kpi['revenue']['value'] ?? 0) + 120 + (float) \App\Support\ContractPricing::total($other->fresh());
        $this->assertEquals($expected, $kpi['revenue_total']['value']);
        $parts = collect($kpi['revenue_total']['parts'])->keyBy('key');
        $this->assertEquals(120, $parts['fees']['value']);
        $this->assertEquals(\App\Support\ContractPricing::total($other->fresh()), $parts['bank_transfers']['value']);
        $metrics = collect($kpi['metrics'])->keyBy('key');
        $this->assertEquals($expected, $metrics['revenue_total_sar']['value']);
        $this->assertSame('إيراد التوثيق', $metrics['revenue_sar']['label_ar']);

        $list = $this->getJson('/api/admin/employees/kpis?period=today')->assertOk()->json('data');
        $this->assertArrayHasKey('revenue_total_sar_total', $list['summary']);
        $this->assertSame('إيراد التوثيق', $list['summary']['revenue_labels']['revenue_sar']);
    }
}
