<?php

namespace Tests\Feature\BatchE;

use App\Models\ContractActivity;
use App\Models\ContractDataRequest;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * دفعة (هـ) — ملاحظات الفحص المتقاطع (issues-for-backend B-1…B-8):
 * B-1/B-2 ملف Excel صالح (بلا workbookView rightToLeft) والجوال نص بصفره البادئ،
 * B-3 بنود payment_details مجموعها = الصافي بعد فرق السعر،
 * B-4/B-5 تنبيه المالك يُعلَّم فقط عند النجاح ونشاط واحد لحدث الـ72 ساعة، B-8 علم 72h في عليك الحين،
 * B-6 روابط العميل المخزّنة على جذر APP_URL لا X-Forwarded-Host.
 */
class CrossQaFixesTest extends BatchETestCase
{
    /** B-1 + B-2 */
    public function test_xlsx_export_is_schema_valid_and_keeps_leading_zero_in_mobile(): void
    {
        $this->employee('admin');
        $user = $this->customer('0551234567');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')], $user);
        $this->payFull($contract);

        $bytes = $this->get('/api/admin/orders/export?format=xlsx')->assertOk()->getContent();
        $parts = $this->unzipXlsx($bytes);

        $workbook = $parts['xl/workbook.xml'];
        $this->assertStringNotContainsString('rightToLeft', $workbook, 'rightToLeft ليست سمة لـ workbookView (CT_BookView)');
        $this->assertStringContainsString('<bookViews><workbookView', $workbook);

        $sheet = $parts['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('<sheetView rightToLeft="1"', $sheet, 'اتجاه الورقة يبقى في sheetView');

        // الصف 2 = أول طلب: A = رقم الطلب (نص)، F = الجوال (نص بصفره)، L = الصافي (رقم).
        $this->assertMatchesRegularExpression('#<c r="A2" t="inlineStr"><is><t>'.preg_quote((string) $contract->uuid, '#').'</t></is></c>#', $sheet);
        $this->assertStringContainsString('<c r="F2" t="inlineStr"><is><t>0551234567</t></is></c>', $sheet);
        $this->assertStringNotContainsString('<v>0551234567</v>', $sheet);
        $this->assertStringContainsString('<c r="L2"><v>249</v></c>', $sheet);
    }

    /** B-3: بعد دفع فرق السعر (إلكتروني → ورقي) مجموع البنود = الصافي، بلا تكرار بند المستند. */
    public function test_payment_details_lines_sum_to_net_after_price_difference(): void
    {
        $this->employee('admin');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')]);
        $this->payFull($contract); // 249 — لقطة الفاتورة محفوظة
        $this->postJson('/api/admin/orders/'.$contract->id.'/stage/received')->assertOk();

        $did = $this->patchJson('/api/admin/orders/'.$contract->id, ['instrument_type' => 'old_handwritten'])->json('data.price_difference.charge.id');
        $this->assertNotNull($did);

        // قبل دفع الفرق: البنود = ما دُفع فعلاً (249) — لا السعر الحي (324).
        $before = $this->getJson('/api/admin/orders/'.$contract->id)->json('data.payment_details');
        $this->assertEquals(249, $this->sumLines($before['lines']));
        $this->assertEquals($before['totals']['net'], $this->sumLines($before['lines']));

        $dkey = Payment::chargeKey((string) $contract->uuid, $did);
        Http::fake(['https://api.moyasar.com/v1/payments/pay_diff' => Http::response(['id' => 'pay_diff', 'status' => 'paid', 'amount' => 7500, 'currency' => 'SAR', 'source' => ['type' => 'creditcard'], 'metadata' => ['contract_uuid' => $dkey, 'charge_id' => (string) $did]], 200)]);
        $this->postJson('/api/status/'.$dkey.'/success', ['id' => 'pay_diff', 'status' => 'paid']);

        $after = $this->getJson('/api/admin/orders/'.$contract->id)->json('data.payment_details');
        $this->assertEquals(324, $after['totals']['net']);
        $this->assertEquals(324, $this->sumLines($after['lines']), 'مجموع lines يجب أن يساوي totals.net');
        $kinds = array_column($after['lines'], 'kind');
        $this->assertSame(1, count(array_keys($kinds, 'price_difference', true)));
        $this->assertNotContains('document', $kinds, 'بند المستند الحي يغطّيه رسم فرق السعر — لا يُكرَّر');

        // الفاتورة التراكمية تُعطي الرقم نفسه.
        $invoice = $this->getJson('/api/admin/orders/'.$contract->id)->json('data.invoice');
        $this->assertEquals(324, $invoice['total_amount']);
        $this->assertEquals(249, $invoice['original_total']);

        // بلا لقطة فاتورة (بيانات قديمة): يبقى المجموع = الصافي (بند «الدفعة الأصلية» أو بند تسوية).
        Invoice::query()->where('contract_id', $contract->id)->delete();
        $legacy = app(\App\Services\Payments\ContractPaymentState::class)->details($contract->fresh());
        $this->assertEquals($legacy['totals']['net'], $this->sumLines($legacy['lines']));
        $this->assertContains('original_payment', array_column($legacy['lines'], 'key'));
        // وعبر اللوحة (التي تعيد حفظ لقطة من السعر الحي) يبقى الثابت نفسه عبر بند التسوية.
        $legacy = $this->getJson('/api/admin/orders/'.$contract->id)->json('data.payment_details');
        $this->assertEquals($legacy['totals']['net'], $this->sumLines($legacy['lines']));
        $this->assertNotEmpty(array_intersect(['original_payment', 'payment_adjustment'], array_column($legacy['lines'], 'key')));

        // طلب غير مدفوع: البنود = السعر الحي المستحق.
        $unpaid = $this->paidContract(['contract_status_id' => $this->statusId('new'), 'is_completed' => 0]);
        $details = $this->getJson('/api/admin/orders/'.$unpaid->id)->json('data.payment_details');
        $this->assertEquals($details['totals']['due'], $this->sumLines($details['lines']));
    }

    /** B-3: لقطة الفاتورة تُحفظ لحظة الدفع الأصلي (webhook) حتى لا يُعاد تعريف «الأصل» بعد تعديل السعر. */
    public function test_invoice_snapshot_is_persisted_on_original_payment(): void
    {
        $this->employee('admin');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('new'), 'is_completed' => 0]);
        $this->assertNull(Invoice::query()->where('contract_id', $contract->id)->first());

        Http::fake(['https://api.moyasar.com/v1/payments/pay_orig' => Http::response(['id' => 'pay_orig', 'status' => 'paid', 'amount' => 24900, 'currency' => 'SAR', 'source' => ['type' => 'creditcard'], 'metadata' => ['contract_uuid' => (string) $contract->uuid]], 200)]);
        $this->postJson('/api/status/'.$contract->uuid.'/success', ['id' => 'pay_orig', 'status' => 'paid']);

        $invoice = Invoice::query()->where('contract_id', $contract->id)->first();
        $this->assertNotNull($invoice);
        $this->assertNotEmpty($invoice->lines['items'] ?? []);
        $this->assertEquals(249, $invoice->total_amount);

        // تعديل يرفع السعر بعدها لا يغيّر الأصل.
        $this->patchJson('/api/admin/orders/'.$contract->id, ['instrument_type' => 'old_handwritten'])->assertOk();
        $details = $this->getJson('/api/admin/orders/'.$contract->id)->json('data.payment_details');
        $this->assertEquals(249, $this->sumLines($details['lines']));
        $this->assertEquals(249, $this->getJson('/api/admin/orders/'.$contract->id)->json('data.invoice.original_total'));
    }

    /** B-4 + B-5 + B-8 */
    public function test_owner_alert_marks_only_on_success_and_logs_single_activity(): void
    {
        $this->employee('manager');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee')]);
        $this->payFull($contract);
        $rid = $this->postJson('/api/admin/orders/'.$contract->id.'/data-requests', ['section' => 'tenant', 'items' => ['tenant_id_unclear']])->json('data.request.id');

        // 30 ساعة: علم 24h يُسجَّل (نشاط واحد) — كما في سيناريو الفحص.
        DB::table('contract_data_requests')->where('id', $rid)->update(['requested_at' => Carbon::now()->subHours(30), 'created_at' => Carbon::now()->subHours(30)]);
        // أول إرسال فعلي لتيليجرام يفشل (500)، والثاني ينجح.
        Http::fake(['https://api.telegram.org/*' => Http::sequence()->push(['ok' => false], 500)->push(['ok' => true], 200)]);
        Artisan::call('orders:flag-delays');
        $this->assertCount(1, ContractActivity::query()->where('contract_id', $contract->id)->where('action', 'delay_flagged')->get());

        DB::table('contract_data_requests')->where('id', $rid)->update(['requested_at' => Carbon::now()->subHours(80), 'created_at' => Carbon::now()->subHours(80)]);

        // B-8: علم 72 ساعة في «عليك الحين» وصف القائمة.
        $item = $this->getJson('/api/admin/orders/attention')->json('data.awaiting_customer.items.0');
        $this->assertSame(['customer_no_reply_24h', 'customer_no_reply_72h'], $item['delay_flags']);
        $this->assertSame(80, $item['hours_waiting']);
        $row = collect($this->getJson('/api/admin/orders?per_page=100')->json('data.items'))->firstWhere('id', $contract->id);
        $this->assertContains('customer_no_reply_72h', $row['delay_flags']);

        // B-4: تيليجرام غير مضبوط ⇒ لا يُعلَّم كمُرسَل، وتُعاد المحاولة.
        config(['services.telegram.bot_token' => null, 'services.telegram.chat_id' => null]);
        Artisan::call('orders:flag-delays');
        $this->assertStringContainsString('owner alerts: 0', Artisan::output());
        Http::assertNothingSent();
        $this->assertNull(ContractDataRequest::query()->find($rid)->owner_alerted_at);
        $seventyTwo = fn () => ContractActivity::query()->where('contract_id', $contract->id)->where('action', 'delay_flagged')->get()
            ->filter(fn (ContractActivity $a) => in_array('customer_no_reply_72h', (array) ($a->after['delay_flags'] ?? []), true))->values();
        $this->assertCount(2, ContractActivity::query()->where('contract_id', $contract->id)->where('action', 'delay_flagged')->get(), '24h ثم 72h');
        $this->assertCount(1, $seventyTwo(), 'نشاط واحد فقط لحدث الـ72 ساعة (B-5)');
        $this->assertSame($rid, $seventyTwo()->first()->after['request_id']);
        $this->assertFalse($seventyTwo()->first()->after['owner_alerted']);

        Artisan::call('orders:flag-delays');
        $this->assertCount(1, $seventyTwo(), 'إعادة المحاولة لا تكرّر النشاط');

        // فشل الإرسال (500) ⇒ كذلك لا يُعلَّم.
        config(['services.telegram.bot_token' => 'bot-test', 'services.telegram.chat_id' => '1']);
        Artisan::call('orders:flag-delays');
        $this->assertStringContainsString('owner alerts: 0', Artisan::output());
        Http::assertSentCount(1);
        $this->assertNull(ContractDataRequest::query()->find($rid)->owner_alerted_at);

        // نجاح الإرسال ⇒ يُعلَّم مرة واحدة ولا يُعاد.
        Artisan::call('orders:flag-delays');
        $this->assertStringContainsString('owner alerts: 1', Artisan::output());
        Http::assertSentCount(2);
        $this->assertNotNull(ContractDataRequest::query()->find($rid)->owner_alerted_at);
        Artisan::call('orders:flag-delays');
        Http::assertSentCount(2);
        $this->assertCount(1, $seventyTwo());
        $this->assertCount(2, ContractActivity::query()->where('contract_id', $contract->id)->where('action', 'delay_flagged')->get());
        $this->assertContains('customer_no_reply_72h', $this->getJson('/api/admin/orders/'.$contract->id)->json('data.delay_flags'));
    }

    /** B-6 */
    public function test_stored_customer_links_use_app_url_not_forwarded_host(): void
    {
        config(['app.url' => 'https://api.contractejar.test']);
        $this->employee('admin');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee')]);
        $this->payFull($contract);

        $created = $this->withHeaders(['X-Forwarded-Host' => 'evil.example.com', 'X-Forwarded-Proto' => 'http'])
            ->postJson('/api/admin/orders/'.$contract->id.'/charges', ['amount' => 120, 'message' => 'رسوم وحدة'])
            ->assertStatus(201)->json('data.charge');
        $this->assertStringStartsWith('https://api.contractejar.test/api/v2/contracts/'.$contract->uuid.'/charges/', $created['payment_url']);

        $offer = Offer::query()->where('kind', 'charge_payment_request')->latest('id')->first();
        $this->assertNotNull($offer);
        $this->assertStringStartsWith('https://api.contractejar.test/api/v2/contracts/', $offer->data['payment_url']);
        $this->assertStringNotContainsString('evil.example.com', json_encode($offer->data));

        $details = $this->withHeaders(['X-Forwarded-Host' => 'evil.example.com'])
            ->getJson('/api/admin/orders/'.$contract->id)->json('data.payment_details');
        $this->assertStringStartsWith('https://api.contractejar.test/api/v2/invoices/print/', $details['invoice_url']);

        // الرابط الموقّع يبقى صالحاً عند فتحه على جذر APP_URL (بلا رؤوس التمرير).
        $this->flushHeaders();
        $this->get($details['invoice_url'])->assertOk();
    }

    /** @return array<string, string> */
    private function unzipXlsx(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx-test');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $parts[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($tmp);

        return $parts;
    }

    /** @param list<array<string, mixed>> $lines */
    private function sumLines(array $lines): float
    {
        return round((float) array_sum(array_map(static fn (array $l) => (float) $l['amount'], $lines)), 2);
    }
}
