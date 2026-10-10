<?php

namespace Tests\Feature\BatchF;

use App\Models\Contract;
use App\Models\ContractPeriod;
use App\Models\ContractStatus;
use App\Models\CustomerReview;
use App\Models\LessorChangeRequest;
use App\Models\Offer;
use App\Models\RealEstate;
use App\Models\ReceivedContract;
use App\Models\Setting;
use App\Models\UnitsReal;
use App\Services\Invoices\InvoicePdfService;
use App\Support\PublicCache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\BatchE\BatchETestCase;

/**
 * دفعة (و) — الجولة 3: قرارات المالك 2026-10-10 (D1–D9) + أخطاء المحاكي (B14، B16).
 */
class OwnerDecisionsTest extends BatchETestCase
{
    // ───────────────────────── D1 ─────────────────────────

    public function test_d1_received_status_is_merged_into_received_by_employee(): void
    {
        $oldId = (int) ContractStatus::query()->where('status_key', ContractStatus::KEY_RECEIVED)->value('id');
        $newId = $this->statusId(ContractStatus::KEY_RECEIVED_BY_EMPLOYEE);
        $this->assertGreaterThan(0, $oldId);

        // طلب قديم في «مستلم» + إعادة تشغيل الترحيل ⇒ ينتقل إلى «مستلم من الموظف».
        DB::table('contract_statuses')->where('id', $oldId)->update(['is_active' => 1]);
        $contract = $this->paidContract(['contract_status_id' => $oldId]);
        (require database_path('migrations/2026_10_11_140000_batch_f_merge_received_status.php'))->up();
        ContractStatus::flushKeyCache();

        $this->assertSame($newId, (int) $contract->fresh()->contract_status_id);
        $this->assertFalse((bool) DB::table('contract_statuses')->where('id', $oldId)->value('is_active'));
        $this->assertTrue(DB::table('contract_status_histories')->where('contract_id', $contract->id)->where('status', 'received_by_employee')->exists());
        $this->assertTrue(DB::table('contract_activities')->where('contract_id', $contract->id)->where('action', 'status_changed')->exists());
        $this->assertContains(ContractStatus::KEY_RECEIVED, ContractStatus::LEGACY_KEYS);

        // قوائم الحالات والتبويبات لا تعرض «مستلم».
        $this->employee('admin');
        $keys = array_column($this->getJson('/api/admin/contract-statuses/active')->assertOk()->json('data'), 'status_key');
        $this->assertNotContains('received', $keys);
        $this->assertContains('received_by_employee', $keys);
        $tabKeys = array_column($this->getJson('/api/admin/orders/status-counts')->assertOk()->json('data.tabs'), 'key');
        $this->assertNotContains('received', $tabKeys);

        // فلتر قديم ?status_key=received = «مستلم من الموظف».
        $ids = array_column($this->getJson('/api/admin/orders?status_key=received')->assertOk()->json('data.items') ?? [], 'id');
        $this->assertContains($contract->id, $ids);

        // اختيار الحالة القديمة يدوياً مرفوض.
        $other = $this->paidContract(['contract_status_id' => $this->statusId('under_review')]);
        $this->postJson('/api/admin/orders/'.$other->id.'/status', ['status_id' => $oldId])
            ->assertStatus(422)->assertJsonPath('code', 'legacy_status');
    }

    // ───────────────────────── D2 ─────────────────────────

    public function test_d2_refunded_is_not_manual_and_is_set_automatically_on_full_refund_only(): void
    {
        config(['services.moyasar.driver' => 'moyasar']);
        $this->employee('admin');
        $refundedId = (int) ContractStatus::refundedId();

        $statuses = collect($this->getJson('/api/admin/contract-statuses/active')->assertOk()->json('data'))->keyBy('status_key');
        $this->assertFalse($statuses['refunded']['manual_selectable']);
        $this->assertSame(ContractStatus::REFUND_AUTO_ONLY_MESSAGE, $statuses['refunded']['manual_hint']);
        $this->assertTrue($statuses['received_by_employee']['manual_selectable']);

        $user = $this->customer('0551110001');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee')], $user);
        $payment = $this->payment($contract, 249, 'pay_d2');
        $payment->forceFill(['gateway_payment_id' => 'pay_d2'])->save();

        $this->postJson('/api/admin/orders/'.$contract->id.'/status', ['status_id' => $refundedId])
            ->assertStatus(422)->assertJsonPath('code', 'refund_auto_only');

        // جزئي ⇒ لا تتغير الحالة.
        Http::fake(['https://api.moyasar.com/v1/payments/pay_d2/refund' => Http::sequence()
            ->push(['id' => 'pay_d2', 'status' => 'paid', 'refunded' => 5000], 200)
            ->push(['id' => 'pay_d2', 'status' => 'refunded', 'refunded' => 24900], 200)]);
        $this->postJson('/api/admin/payments/'.$payment->id.'/refund', ['amount' => 50, 'reason' => 'جزئي'])->assertOk();
        $this->assertNotSame($refundedId, (int) $contract->fresh()->contract_status_id);

        // كامل (الباقي) ⇒ «مسترجع» تلقائياً + سجل + إشعار.
        $this->postJson('/api/admin/payments/'.$payment->id.'/refund', ['reason' => 'الباقي'])->assertOk();
        $this->assertSame($refundedId, (int) $contract->fresh()->contract_status_id);
        $this->assertTrue(DB::table('contract_status_histories')->where('contract_id', $contract->id)->where('status_id', $refundedId)->exists());
        $this->assertSame(2, Offer::query()->where('user_id', $user->id)->where('kind', 'refund')->count());
    }

    // ───────────────────────── D3 ─────────────────────────

    public function test_d3_three_and_six_month_periods_are_active_with_server_prices(): void
    {
        foreach (['housing' => 249.0, 'commercial' => 349.0] as $type => $firstYear) {
            $rows = collect($this->getJson('/api/v2/contract-periods?contract_type='.$type)->assertOk()->json('data'));
            $byMonths = $rows->keyBy('months');
            $this->assertTrue($byMonths->has(3), "3 months missing for {$type}");
            $this->assertTrue($byMonths->has(6), "6 months missing for {$type}");
            $this->assertSame('3_months', $byMonths[3]['duration_preset']);
            $this->assertSame('6_months', $byMonths[6]['duration_preset']);
            $this->assertEquals($firstYear, $byMonths[3]['doc_fee']);
            $this->assertEquals($firstYear, $byMonths[6]['doc_fee']);
            $this->assertEquals($firstYear, $byMonths[12]['doc_fee']);
        }

        // التسعير من الخادم: مدة 3 أشهر = سنة واحدة.
        $period = ContractPeriod::query()->where('contract_type', 'housing')->where('months', 3)->firstOrFail();
        $contract = $this->contract(['duration_preset' => null, 'total_months' => null, 'contract_term_in_years' => $period->id]);
        $this->assertEquals(249.0, \App\Support\ContractPricing::total($contract->fresh()));
        $this->assertSame(3, \App\Support\DocFee::contractMonths($contract->fresh()));
    }

    // ───────────────────────── D4 ─────────────────────────

    public function test_d4_real_arabic_pdf_invoice_for_paid_order_only(): void
    {
        $user = $this->customer('0551110002');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')], $user);

        // قبل الدفع: لا رابط PDF.
        $unpaid = $this->contract([], $user);
        Sanctum::actingAs($user);
        $json = $this->getJson('/api/v2/contracts/'.$unpaid->id)->assertOk()->json('data');
        $this->assertNull($json['invoice_pdf_url']);
        $this->assertFalse($json['has_invoice']);
        $this->assertFalse($json['is_paid']);

        $this->payFull($contract);
        $json = $this->getJson('/api/v2/contracts/'.$contract->id)->assertOk()->json('data');
        $this->assertTrue($json['has_invoice']);
        $this->assertNotNull($json['invoice_url']);
        $url = $json['invoice_pdf_url'];
        $this->assertNotNull($url);
        $this->assertSame($url, $json['payment_details']['invoice_pdf_url']);

        $invoice = $this->getJson('/api/v2/contracts/'.$contract->id.'/invoice')->assertOk()->json('data');
        $this->assertNotNull($invoice['invoice_pdf_url']);
        $this->assertSame('فاتورة', $invoice['invoice_title']);

        // الرابط موقّع: بلا توقيع ⇒ 403.
        $this->get('/api/v2/invoices/pdf/'.$contract->id)->assertStatus(403);

        $res = $this->get($this->relative($url));
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $pdf = $res->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('attachment', (string) $this->get($this->relative($url).'&download=1')->headers->get('Content-Disposition'));

        $text = $this->pdfText($pdf);
        if ($text !== null) {
            $this->assertStringContainsString('فاتورة', $text);
            $this->assertStringContainsString('عقدي', $text);
            $this->assertStringNotContainsString('ضريبية', $text);
            $this->assertStringContainsString((string) $contract->uuid, $text);
        }
    }

    public function test_d4_lessor_change_pdf_invoice(): void
    {
        $user = $this->customer('0551110003');
        $row = LessorChangeRequest::query()->create([
            'uuid' => '77001122', 'user_id' => $user->id, 'mobile' => '966551110003',
            'old_deed_image' => 'x', 'new_deed_image' => 'y', 'new_owner_id_number' => '1098765432',
            'new_owner_dob' => '10/05/1410', 'new_owner_dob_type' => 'hijri',
            'fee' => 400, 'status' => 'pending_payment', 'platform' => 'web',
        ]);
        $this->assertNull(InvoicePdfService::lessorChangeUrl($row));
        $row->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
        DB::table('payments')->insert([
            'name' => 'pay_lc', 'contract_uuid' => '77001122', 'amount' => 400, 'status' => 'success',
            'payment_method' => 'moyasar', 'payment_brand' => 'mada',
            'tran_currency' => 'SAR', 'payment_date' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $url = InvoicePdfService::lessorChangeUrl($row->fresh());
        $this->assertNotNull($url);
        $this->assertArrayHasKey('invoice_pdf_url', $row->fresh()->toClientArray());
        $res = $this->get($this->relative($url))->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    // ───────────────────────── D6 ─────────────────────────

    public function test_d6_property_and_unit_trash_restore_and_purge(): void
    {
        $user = $this->customer('0551110004');
        $property = RealEstate::query()->forceCreate(['user_id' => $user->id, 'name_real_estate' => 'عمارة النخيل', 'contract_type' => 'housing']);
        $unitA = UnitsReal::query()->forceCreate(['user_id' => $user->id, 'real_estates_units_id' => $property->id, 'unit_number' => '1']);
        $unitB = UnitsReal::query()->forceCreate(['user_id' => $user->id, 'real_estates_units_id' => $property->id, 'unit_number' => '2']);
        Sanctum::actingAs($user);

        // حذف وحدة ⇒ للمحذوفات.
        $res = $this->deleteJson('/api/v2/unit/delete/'.$unitA->id)->assertOk()->json('data');
        $this->assertTrue($res['trashed']);
        $this->assertSame(30, $res['days_left']);
        $this->assertNull(UnitsReal::query()->find($unitA->id));
        $this->assertNotNull(UnitsReal::withTrashed()->find($unitA->id));
        $this->travel(2)->minutes();

        // حذف العقار ⇒ هو ووحدته الباقية للمحذوفات، ولا يظهر في القوائم.
        $this->deleteJson('/api/v2/realstate/delete/'.$property->id)->assertOk()->assertJsonPath('data.trashed', true);
        $this->assertNull(RealEstate::query()->find($property->id));
        $this->assertNull(UnitsReal::query()->find($unitB->id));
        $list = $this->getJson('/api/v2/realstate/all')->assertOk()->json('data');
        $this->assertStringNotContainsString('عمارة النخيل', json_encode($list, JSON_UNESCAPED_UNICODE));

        $trash = $this->getJson('/api/v2/realstate/trash')->assertOk()->json('data');
        $this->assertSame([$property->id], array_column($trash['real_estates'], 'id'));
        $this->assertEqualsCanonicalizing([$unitA->id, $unitB->id], array_column($trash['units'], 'id'));

        // استرجاع العقار ⇒ وحدته التي حُذفت معه تعود، والمحذوفة قبله تبقى.
        $this->travel(10)->seconds();
        $this->postJson('/api/v2/realstate/'.$property->id.'/restore')->assertOk();
        $this->assertNotNull(RealEstate::query()->find($property->id));
        $this->assertNotNull(UnitsReal::query()->find($unitB->id));
        $this->assertNull(UnitsReal::query()->find($unitA->id));

        // استرجاع وحدة من اللوحة.
        $this->employee('admin');
        $admin = $this->getJson('/api/admin/real-estates/trash')->assertOk()->json('data');
        $this->assertSame([$unitA->id], array_column($admin['units'], 'id'));
        $this->postJson('/api/admin/real-estates/units/'.$unitA->id.'/restore')->assertOk();
        $this->assertNotNull(UnitsReal::query()->find($unitA->id));

        // عميل آخر لا يسترجع عقار غيره.
        Sanctum::actingAs($user);
        $this->deleteJson('/api/v2/realstate/delete/'.$property->id)->assertOk();
        Sanctum::actingAs($this->customer('0551110005'));
        $this->postJson('/api/v2/realstate/'.$property->id.'/restore')->assertNotFound();

        // بعد 30 يوماً: الاسترجاع مرفوض والتنظيف يحذف نهائياً.
        $this->travel(31)->days();
        Sanctum::actingAs($user);
        $this->postJson('/api/v2/realstate/'.$property->id.'/restore')->assertStatus(422);
        $this->artisan('trash:purge')->assertSuccessful();
        $this->assertNull(RealEstate::withTrashed()->find($property->id));
        $this->assertNull(UnitsReal::withTrashed()->find($unitA->id));
    }

    public function test_d6_linked_unit_cannot_be_trashed_and_purge_keeps_linked_rows(): void
    {
        $user = $this->customer('0551110006');
        $property = RealEstate::query()->forceCreate(['user_id' => $user->id, 'name_real_estate' => 'برج', 'contract_type' => 'housing']);
        $unit = UnitsReal::query()->forceCreate(['user_id' => $user->id, 'real_estates_units_id' => $property->id, 'unit_number' => '9']);
        $contract = $this->contract(['real_units_id' => $unit->id, 'real_id' => $property->id], $user);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v2/unit/delete/'.$unit->id)->assertStatus(422);
        $this->deleteJson('/api/v2/realstate/delete/'.$property->id)->assertStatus(422);

        // الطلب يبقى يعرض عقاره حتى لو كان محذوفاً (withTrashed).
        $property->forceFill(['trashed_at' => now()->subDays(40)])->save();
        $this->assertNotNull($contract->fresh()->realEstate);
        app(\App\Services\RealEstate\PropertyTrashService::class)->purge();
        $this->assertNotNull(RealEstate::withTrashed()->find($property->id));
    }

    // ───────────────────────── D7 ─────────────────────────

    public function test_d7_reviews_public_endpoint_seeded_and_admin_crud(): void
    {
        $this->assertGreaterThanOrEqual(80, CustomerReview::query()->count());
        $public = $this->getJson('/api/v2/reviews?limit=5')->assertOk()->json('data');
        $this->assertEquals(4.7, $public['summary']['average']);
        $this->assertSame(3000, $public['summary']['count']);
        $this->assertTrue($public['summary']['enabled']);
        $this->assertCount(5, $public['reviews']);
        $this->assertSame(1, $public['reviews'][0]['sort_order']);
        $this->assertSame($public['summary'], $this->getJson('/api/v2/settings')->assertOk()->json('data.reviews_summary'));

        // صلاحية: موظف بلا customer_reviews ⇒ 403.
        $this->limitedEmployee(['all_requests.view']);
        $this->getJson('/api/admin/customer-reviews')->assertStatus(403);

        $this->employee('admin');
        $created = $this->postJson('/api/admin/customer-reviews', ['name' => 'نورة ع.', 'city' => 'الرياض', 'text' => 'خدمة ممتازة', 'rating' => 5, 'sort_order' => 0])
            ->assertStatus(201)->json('data');
        $this->postJson('/api/admin/customer-reviews', ['name' => 'x', 'text' => 'y', 'rating' => 9])->assertStatus(422);
        $this->postJson('/api/admin/customer-reviews/'.$created['id'], ['is_visible' => false, 'text' => 'خدمة ممتازة جداً'])->assertOk()
            ->assertJsonPath('data.is_visible', false);
        $this->postJson('/api/admin/customer-reviews/settings', ['reviews_average' => 4.8, 'reviews_count' => 3500, 'reviews_enabled' => false])->assertOk()
            ->assertJsonPath('data.reviews_count', 3500);
        $this->postJson('/api/admin/customer-reviews/settings', ['reviews_average' => 7])->assertStatus(422);

        $public = $this->getJson('/api/v2/reviews?limit=200')->assertOk()->json('data');
        $this->assertFalse($public['summary']['enabled']);
        $this->assertEquals(4.8, $public['summary']['average']);
        $this->assertNotContains($created['id'], array_column($public['reviews'], 'id'));

        $first = CustomerReview::query()->ordered()->skip(1)->first();
        $this->postJson('/api/admin/customer-reviews/reorder', ['ids' => [$first->id]])->assertOk();
        $this->assertSame(1, (int) $first->fresh()->sort_order);
        $this->postJson('/api/admin/customer-reviews/'.$created['id'].'/delete')->assertOk();
        $this->assertNull(CustomerReview::query()->find($created['id']));
    }

    // ───────────────────────── D8 ─────────────────────────

    public function test_d8_working_hours_default_and_structured(): void
    {
        $data = $this->getJson('/api/v2/settings')->assertOk()->json('data');
        $this->assertSame(Setting::DEFAULT_WORKING_HOURS, $data['working_hours']);
        $this->assertStringContainsString('12 ظهراً', $data['working_hours_text']);
        $this->assertStringContainsString('الجمعة من 3 عصراً', $data['working_hours_text']);
        $this->assertSame(['Friday'], $data['opening_hours'][1]['days']);
        $this->assertSame('15:00', $data['opening_hours'][1]['opens']);

        $this->employee('admin');
        $this->postJson('/api/admin/settings', ['working_hours' => 'نص مخصص'])->assertOk();
        PublicCache::flush();
        $this->assertSame('نص مخصص', $this->getJson('/api/v2/settings')->json('data.working_hours'));
    }

    // ───────────────────────── D9 ─────────────────────────

    public function test_d9_pay_after_draft_flow(): void
    {
        Storage::fake('local');
        $user = $this->customer('0551110007');
        $contract = $this->contract(['contract_status_id' => $this->statusId('new')], $user);
        Sanctum::actingAs($user);

        // مُعطّل افتراضياً.
        $this->assertFalse($this->getJson('/api/v2/settings')->json('data.pay_after_draft_enabled'));
        $this->postJson('/api/v2/contract/'.$contract->uuid.'/pay-after-draft')->assertStatus(422)->assertJsonPath('code', 'pay_after_draft_disabled');

        $this->employee('admin');
        $this->postJson('/api/admin/settings', ['pay_after_draft_enabled' => true])->assertOk()
            ->assertJsonPath('data.settings.pay_after_draft_enabled', true);
        PublicCache::flush();
        $this->assertTrue($this->getJson('/api/v2/settings')->json('data.pay_after_draft_enabled'));

        Sanctum::actingAs($user);
        $this->postJson('/api/v2/contract/'.$contract->uuid.'/pay-after-draft')->assertOk()
            ->assertJsonPath('data.pay_after_draft', true);
        $this->assertSame(ContractStatus::newId(), (int) $contract->fresh()->contract_status_id);

        // الموظف يرفع المسودة ⇒ مرفق + سجل + إشعار draft_ready.
        $this->employee('admin');
        $this->post('/api/admin/orders/'.$contract->id.'/draft-document', ['file' => UploadedFile::fake()->create('x.exe', 10)], ['Accept' => 'application/json'])->assertStatus(422);
        $detail = $this->post('/api/admin/orders/'.$contract->id.'/draft-document', [
            'file' => UploadedFile::fake()->create('draft.pdf', 50, 'application/pdf'), 'note' => 'راجع البنود',
        ], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame('draft.pdf', $detail['draft_document']['name']);
        $this->assertTrue($detail['pay_after_draft']);
        $this->assertNotNull($detail['draft_document']['uploaded_by']);
        $this->assertContains('draft_document_uploaded', array_column($detail['activities'], 'action'));
        $offer = Offer::query()->where('user_id', $user->id)->where('kind', 'draft_ready')->firstOrFail();
        $this->assertStringContainsString('مسودة عقدك جاهزة', $offer->body);
        // لا حالة جديدة ولا خطوة رحلة.
        $this->assertSame(ContractStatus::newId(), (int) $contract->fresh()->contract_status_id);

        Sanctum::actingAs($user);
        $json = $this->getJson('/api/v2/contracts/'.$contract->id)->assertOk()->json('data');
        $this->assertNotNull($json['draft_document']['url']);
        $this->assertTrue($json['draft_document']['is_pdf']);
        $this->assertArrayNotHasKey('uploaded_by', $json['draft_document']);
        $this->assertCount(3, $json['journey']);
        $this->get($this->relative($json['draft_document']['url']))->assertOk();

        $track = $this->postJson('/api/v2/contract/track', ['order' => (string) $contract->uuid, 'mobile' => '0551110007'])->assertOk()->json('data');
        $this->assertNotNull($track['draft_document']);
        $this->assertTrue($track['awaiting_payment']);

        // مدفوع ⇒ الخيار مرفوض.
        $paid = $this->paidContract([], $user);
        $this->payFull($paid);
        $this->postJson('/api/v2/contract/'.$paid->uuid.'/pay-after-draft')->assertStatus(422)->assertJsonPath('code', 'already_paid');

        $this->employee('admin');
        $this->postJson('/api/admin/orders/'.$contract->id.'/draft-document/delete')->assertOk()->assertJsonPath('data.draft_document', null);
    }

    // ───────────────────────── B14 / B16 ─────────────────────────

    public function test_b14_submitted_unpaid_or_received_orders_are_not_resumable_drafts(): void
    {
        $user = $this->customer('0551110008');
        $submitted = $this->contract(['contract_type' => 'housing', 'step' => 7, 'contract_status_id' => $this->statusId('new')], $user);
        Sanctum::actingAs($user);

        $this->postJson('/api/v2/contract/check-uncompleted-contract?contract_type=housing')->assertStatus(405);
        $check = $this->getJson('/api/v2/contract/check-uncompleted-contract?contract_type=housing')->assertOk()->json('data');
        $this->assertFalse($check['check']);

        $json = $this->getJson('/api/v2/contracts/'.$submitted->id)->assertOk()->json('data');
        $this->assertTrue($json['is_submitted']);
        $this->assertFalse($json['is_resumable_draft']);

        // مسودة حقيقية (step 5) ⇒ قابلة للاستئناف، مع رقم الطلب لا المعرّف.
        $draft = $this->contract(['contract_type' => 'housing', 'step' => 5, 'contract_status_id' => $this->statusId('new')], $user);
        $check = $this->getJson('/api/v2/contract/check-uncompleted-contract?contract_type=housing')->assertOk()->json('data');
        $this->assertTrue($check['check']);
        $this->assertSame($draft->id, $check['contract_id']);
        $this->assertSame((string) $draft->uuid, $check['order_number']);
        $this->assertTrue($this->getJson('/api/v2/contracts/'.$draft->id)->json('data.is_resumable_draft'));

        // نفس المسودة لكن استلمها موظف (أو حالتها تجاوزت «جديد») ⇒ ليست مسودة.
        ReceivedContract::query()->forceCreate(['contract_id' => $draft->id, 'employee_id' => $this->employee('admin', false)->id]);
        Sanctum::actingAs($user);
        $this->assertFalse($this->getJson('/api/v2/contract/check-uncompleted-contract?contract_type=housing')->json('data.check'));
        $this->assertFalse($this->getJson('/api/v2/contracts/'.$draft->id)->json('data.is_resumable_draft'));
    }

    public function test_b16_invoice_label_is_not_tax_invoice(): void
    {
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')]);
        $this->payFull($contract);
        $html = $this->get($this->relative(\App\Services\Payments\ContractPaymentState::invoiceUrl($contract)))->assertOk()->getContent();
        $this->assertStringNotContainsString('ضريبية', $html);
        $payload = app(\App\Services\ContractInvoiceService::class)->forContract($contract->fresh());
        $this->assertStringNotContainsString('ضريبية', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    // ───────────────────────── متابعات المنسّق ─────────────────────────

    public function test_followup_pdf_download_url_and_admin_payments_rows(): void
    {
        $user = $this->customer('0551110010');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')], $user);
        $this->payFull($contract);

        Sanctum::actingAs($user);
        $json = $this->getJson('/api/v2/contracts/'.$contract->id)->assertOk()->json('data');
        $this->assertNotNull($json['invoice_pdf_download_url']);
        $this->assertStringContainsString('download=1', $json['invoice_pdf_download_url']);
        $res = $this->get($this->relative($json['invoice_pdf_download_url']))->assertOk();
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertNotNull($json['payment_details']['invoice_pdf_download_url']);

        $unpaid = $this->contract([], $user);
        $this->assertNull($this->getJson('/api/v2/contracts/'.$unpaid->id)->json('data.invoice_pdf_download_url'));

        $this->employee('admin');
        $rows = collect($this->getJson('/api/admin/payments')->assertOk()->json('data.items'));
        $row = $rows->firstWhere('contract_uuid', (string) $contract->uuid);
        $this->assertNotNull($row);
        $this->assertNotNull($row['invoice_pdf_url']);
        $this->assertSame('contract', $row['invoice_source']);
        $this->get($this->relative($row['invoice_pdf_url']))->assertOk();
    }

    public function test_followup_old_working_hours_and_faq_are_replaced(): void
    {
        DB::table('settings')->update(['working_hours' => 'الإثنين – الجمعة: 9:00 ص – 6:00 م']);
        $faq = \App\Models\Question::query()->create([
            'title_ar' => 'متى أستلم العقد؟', 'title_en' => 'When?',
            'answer_ar' => 'المدة المتوقعة أقل من 30 دقيقة خلال أوقات العمل، من الساعة 9:00 صباحًا حتى الساعة 1:00 بعد منتصف الليل. (يوميًا)',
            'answer_en' => 'x',
        ]);
        $other = \App\Models\Question::query()->create([
            'title_ar' => 'مدة التوثيق', 'title_en' => 'y', 'answer_ar' => 'عادةً تتم عملية التوثيق خلال 3-5 أيام عمل.', 'answer_en' => 'y',
        ]);
        DB::table('settings')->update(['working_hours_en' => null]);

        (require database_path('migrations/2026_10_11_160000_batch_f_working_hours_and_faq.php'))->up();

        $this->assertSame(Setting::DEFAULT_WORKING_HOURS, DB::table('settings')->value('working_hours'));
        $this->assertSame(\App\Support\WorkingHoursText::EN, DB::table('settings')->value('working_hours_en'));
        $answer = $faq->fresh()->answer_ar;
        $this->assertStringContainsString('يومياً من 12 ظهراً حتى 12 منتصف الليل، والجمعة من 3 عصراً', $answer);
        $this->assertStringStartsWith('المدة المتوقعة أقل من 30 دقيقة', $answer);
        $this->assertStringNotContainsString('9:00', $answer);
        $this->assertSame('عادةً تتم عملية التوثيق خلال 3-5 أيام عمل.', $other->fresh()->answer_ar);

        // نص مخصص من المالك لا يُمس.
        DB::table('settings')->update(['working_hours' => 'نص المالك']);
        (require database_path('migrations/2026_10_11_160000_batch_f_working_hours_and_faq.php'))->up();
        $this->assertSame('نص المالك', DB::table('settings')->value('working_hours'));
        PublicCache::flush();
        $this->assertSame(\App\Support\WorkingHoursText::EN, $this->getJson('/api/v2/settings')->json('data.working_hours_en'));
    }

    public function test_followup_customer_reviews_permissions_exist_for_role_editor(): void
    {
        foreach (['view', 'create', 'edit', 'delete'] as $action) {
            $this->assertTrue(\App\Models\Permission::query()->where('name', 'customer_reviews.'.$action)->exists(), $action);
        }
        $this->employee('admin');
        $form = $this->getJson('/api/admin/roles/create')->assertOk()->json('data');
        $this->assertContains('customer_reviews', array_column($form['permission_sections'], 'section_key'));
        $this->assertStringContainsString('customer_reviews.edit', json_encode($form['permission_modules'], JSON_UNESCAPED_UNICODE));
        // موظف بدور يملك customer_reviews.view يصل للقائمة.
        $this->limitedEmployee(['customer_reviews.view']);
        $this->getJson('/api/admin/customer-reviews')->assertOk();
    }

    // ───────────────────────── helpers ─────────────────────────

    private function relative(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function pdfText(string $pdf): ?string
    {
        $bin = trim((string) @shell_exec('command -v pdftotext'));
        if ($bin === '') {
            return null;
        }
        $file = tempnam(sys_get_temp_dir(), 'inv').'.pdf';
        file_put_contents($file, $pdf);
        $text = (string) shell_exec(escapeshellcmd($bin).' '.escapeshellarg($file).' - 2>/dev/null');
        @unlink($file);

        return \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
    }
}
