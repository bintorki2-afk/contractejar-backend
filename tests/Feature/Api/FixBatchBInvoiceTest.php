<?php

namespace Tests\Feature\Api;

use App\Models\Contract;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Invoice;
use App\Models\LessorChangeRequest;
use App\Models\Payment;
use App\Models\Setting;
use App\Modules\Users\Models\User;
use App\Services\ContractInvoiceService;
use App\Support\DocFee;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * دفعة الإصلاحات (ب) — ف1: بنود الفاتورة من ContractPricing، لقطة البنود، وفاتورة تغيير المؤجر.
 */
class FixBatchBInvoiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => 'ContractPeriodSeeder', '--force' => true]);
        Setting::query()->create([
            'whatsapp' => '966500000000',
            'electricity_meter_fee_housing_tenant' => 15,
            'water_meter_fee_housing_tenant' => 15,
            'electricity_meter_fee_commercial_tenant' => 25,
            'water_meter_fee_commercial_tenant' => 25,
        ]);
        DocFee::flushSettingsCache();
    }

    private function customer(): User
    {
        $user = User::query()->create([
            'name' => 'عميل',
            'mobile' => '0551234567',
            'email' => 'c'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function paidHousingContract(User $user, array $overrides = []): Contract
    {
        $contract = Contract::query()->create(array_merge([
            'uuid' => '700001', 'user_id' => $user->id, 'contract_type' => 'housing',
            'instrument_type' => 'old_handwritten', 'duration_preset' => '1_year',
            'total_months' => 12, 'step' => 7, 'is_completed' => 1,
            'electricity_meter_ownership' => 'tenant', 'water_meter_ownership' => 'owner',
        ], $overrides));

        Payment::query()->create([
            'payment_date' => now()->toDateString(), 'contract_uuid' => $contract->uuid,
            'payment_method' => 'moyasar', 'payment_brand' => 'mada', 'tran_currency' => 'SAR',
            'name' => 'pay_test_'.$contract->uuid, 'amount' => 339, 'status' => 'success',
        ]);

        return $contract;
    }

    public function test_invoice_lines_come_from_contract_pricing(): void
    {
        $user = $this->customer();
        $contract = $this->paidHousingContract($user);

        $response = $this->getJson('/api/v2/invoices/'.$contract->id)->assertOk();
        $data = $response->json('data');

        $this->assertSame('contract', $data['kind']);
        $this->assertCount(3, $data['items']);
        $this->assertSame(['fee', 'document_surcharge', 'electricity_meter'], array_column($data['items'], 'key'));
        $this->assertSame('رسوم توثيق عقد إيجار سكني — سنة', $data['items'][0]['description']);
        $this->assertSame(249.0, (float) $data['items'][0]['amount']);
        $this->assertSame('رسوم المستندات الإضافية', $data['items'][1]['description']);
        $this->assertSame(75.0, (float) $data['items'][1]['amount']);
        $this->assertSame('رسوم نقل عداد الكهرباء باسم المستأجر', $data['items'][2]['description']);
        $this->assertSame(15.0, (float) $data['items'][2]['amount']);

        $this->assertSame(339.0, (float) $data['subtotal']);
        $this->assertSame(0.0, (float) $data['discount']);
        $this->assertSame('مجانًا', $data['vat_label']);
        $this->assertSame(339.0, (float) $data['total_amount']);
        $this->assertSame('339 ريال', $data['total_amount_label']);
        $this->assertFalse($data['amount_mismatch']);
        $this->assertSame('#'.$contract->uuid, $data['order_number']);
        $this->assertSame('عقد إيجار', $data['platform_name']);

        // الصف المحفوظ يحمل لقطة البنود ولا يتغيّر عند تعديل الأسعار لاحقاً.
        $invoice = Invoice::query()->where('contract_id', $contract->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame(249.0, (float) $invoice->service_fees);
        $this->assertSame(339.0, (float) $invoice->total_amount);
        $this->assertCount(3, $invoice->lines['items']);

        Setting::query()->update(['doc_fee_housing_first_year' => 999, 'document_surcharge_fee' => 5]);
        DocFee::flushSettingsCache();

        $again = $this->getJson('/api/v2/invoices/'.$contract->id)->assertOk()->json('data');
        $this->assertSame(249.0, (float) $again['items'][0]['amount']);
        $this->assertSame(339.0, (float) $again['total_amount']);
    }

    public function test_invoice_total_follows_payment_and_flags_mismatch(): void
    {
        $user = $this->customer();
        $contract = $this->paidHousingContract($user, ['uuid' => '700002']);
        Payment::query()->where('contract_uuid', $contract->uuid)->update(['amount' => 300]);

        $data = $this->getJson('/api/v2/invoices/'.$contract->id)->assertOk()->json('data');

        $this->assertTrue($data['amount_mismatch']);
        $this->assertSame(300.0, (float) $data['total_amount']);
        $this->assertSame(339.0, (float) $data['computed_total']);

        // القراءة الثانية (من اللقطة) تعطي نفس النتيجة.
        $second = $this->getJson('/api/v2/invoices/'.$contract->id)->assertOk()->json('data');
        $this->assertTrue($second['amount_mismatch']);
        $this->assertSame(300.0, (float) $second['total_amount']);
    }

    public function test_coupon_discount_is_a_negative_line(): void
    {
        $user = $this->customer();
        $contract = $this->paidHousingContract($user, [
            'uuid' => '700003', 'instrument_type' => 'electronic',
            'electricity_meter_ownership' => 'owner',
        ]);
        Payment::query()->where('contract_uuid', $contract->uuid)->update(['amount' => 224.1]);

        $coupon = Coupon::query()->create([
            'name' => 'ترحيب', 'code_coupon' => 'HELLO10', 'type_coupon' => 'ratio', 'value_coupon' => 10,
            'date_start' => now()->subDay()->toDateString(), 'date_end' => now()->addDay()->toDateString(),
            'usage' => 5, 'usage_of_user' => 1, 'is_review' => true, 'is_delete' => false,
        ]);
        CouponUsage::query()->create([
            'user_id' => $user->id, 'coupon_id' => $coupon->id, 'contract_uuid' => $contract->uuid, 'used_at' => now(),
        ]);

        $data = $this->getJson('/api/v2/invoices/'.$contract->id)->assertOk()->json('data');

        $this->assertSame(['fee', 'coupon'], array_column($data['items'], 'key'));
        $this->assertSame('خصم كوبون HELLO10', $data['items'][1]['description']);
        $this->assertSame(-24.9, (float) $data['items'][1]['amount']);
        $this->assertTrue($data['items'][1]['is_discount']);
        $this->assertSame(249.0, (float) $data['subtotal']);
        $this->assertSame(24.9, (float) $data['discount']);
        $this->assertSame('HELLO10', $data['coupon_code']);
        $this->assertSame(224.1, (float) $data['total_amount']);
        $this->assertFalse($data['amount_mismatch']);
    }

    public function test_lessor_change_invoice_and_combined_list(): void
    {
        $user = $this->customer();
        $contract = $this->paidHousingContract($user, ['uuid' => '700004']);

        $request = LessorChangeRequest::query()->create([
            'uuid' => '700005', 'user_id' => $user->id, 'mobile' => '966551234567',
            'old_deed_image' => 'x', 'new_deed_image' => 'y', 'new_owner_id_number' => '1098765432',
            'new_owner_dob' => '10/05/1410', 'new_owner_dob_type' => 'hijri',
            'fee' => 400, 'status' => 'paid', 'paid_at' => now(), 'platform' => 'web',
        ]);
        Payment::query()->create([
            'payment_date' => now()->toDateString(), 'contract_uuid' => '700005',
            'payment_method' => 'moyasar', 'payment_brand' => 'mada', 'tran_currency' => 'SAR',
            'name' => 'pay_lc', 'amount' => 400, 'status' => 'success',
        ]);

        $data = $this->getJson('/api/v2/lessor-change/700005/invoice')->assertOk()->json('data');
        $this->assertSame('lessor_change', $data['kind']);
        $this->assertCount(1, $data['items']);
        $this->assertSame('رسوم خدمة تغيير المؤجر', $data['items'][0]['description']);
        $this->assertSame(400.0, (float) $data['items'][0]['amount']);
        $this->assertSame(400.0, (float) $data['total_amount']);
        $this->assertSame('مجانًا', $data['vat_label']);
        $this->assertSame('INV-LC-'.$request->id, $data['invoice_number']);

        // قائمة الفواتير تجمع فواتير العقود وطلبات تغيير المؤجر.
        $list = $this->getJson('/api/v2/invoices')->assertOk()->json('data.data');
        $this->assertCount(2, $list);
        $this->assertEqualsCanonicalizing(['contract', 'lessor_change'], array_column($list, 'kind'));

        $this->getJson('/api/v2/invoices/number/INV-LC-'.$request->id)->assertOk()->assertJsonPath('data.kind', 'lessor_change');

        // ليست ملكه → 404
        $other = User::query()->create(['name' => 'آخر', 'mobile' => '0559999999', 'email' => 'o@test.local', 'password' => bcrypt('x'), 'is_active' => true]);
        Sanctum::actingAs($other, ['*']);
        $this->getJson('/api/v2/lessor-change/700005/invoice')->assertStatus(404);
        $this->getJson('/api/v2/invoices/'.$contract->id)->assertStatus(404);
    }

    /**
     * فحص (ج): طلب مدفوع لكن السعر المحسوب 0 (مدة غير مضبوطة مثلاً) →
     * الفاتورة تعرض مبلغ الدفعة الناجحة وترفع علم عدم التطابق، ولا تظهر 0 أبداً.
     */
    public function test_invoice_never_shows_zero_when_a_successful_payment_exists(): void
    {
        $user = $this->customer();

        // عقد تجاري بلا مدة/أداة → ContractPricing يحسب 0.
        $contract = Contract::query()->create([
            'uuid' => '700010', 'user_id' => $user->id, 'contract_type' => 'commercial',
            'step' => 7, 'is_completed' => 1,
        ]);
        Payment::query()->create([
            'payment_date' => now()->toDateString(), 'contract_uuid' => $contract->uuid,
            'payment_method' => 'moyasar', 'payment_brand' => 'mada', 'tran_currency' => 'SAR',
            'name' => 'pay_700010', 'amount' => 895, 'status' => 'success',
        ]);

        $data = $this->getJson('/api/v2/invoices/'.$contract->id)->assertOk()->json('data');

        $this->assertSame(895.0, (float) $data['total_amount']);
        $this->assertTrue($data['amount_mismatch']);
        $this->assertSame(0.0, (float) $data['computed_total']);
        $this->assertNotSame(0.0, (float) $data['total_amount']);
    }

    /**
     * فحص (ج): طلب مدفوع عبر موظف (ContractPaidByEmployee) بلا صف payment ناجح →
     * الفاتورة تعرض المبلغ المدفوع لا 0.
     */
    public function test_invoice_uses_employee_paid_amount_when_no_payment_row(): void
    {
        $user = $this->customer();

        $employeeId = DB::table('employees')->insertGetId([
            'name' => 'موظف', 'email' => 'emp'.uniqid().'@test.local',
            'password' => bcrypt('x'), 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $contract = Contract::query()->create([
            'uuid' => '700011', 'user_id' => $user->id, 'contract_type' => 'commercial',
            'step' => 7, 'is_completed' => 1,
        ]);

        DB::table('contract_paid_by_employees')->insert([
            'contract_uuid' => $contract->uuid, 'employee_id' => $employeeId,
            'customer_mobile' => '0551234567', 'amount' => 349, 'is_paid' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->getJson('/api/v2/invoices/'.$contract->id)->assertOk()->json('data');

        $this->assertSame(349.0, (float) $data['total_amount']);
        $this->assertTrue($data['amount_mismatch']);
    }

    /**
     * CROSS-1: الملخص المالي لا يعيد بنود «خدمات» تجريبية (كانت تظهر كبنود فاتورة وهمية).
     */
    public function test_financial_summary_does_not_return_phantom_services(): void
    {
        $user = $this->customer();
        $contract = $this->paidHousingContract($user, ['uuid' => '700020', 'instrument_type' => 'electronic']);

        $data = $this->getJson('/api/v2/financial/'.$contract->uuid)->assertOk()->json('data');

        $this->assertSame([], $data['services']);
        $this->assertSame([], $data['additional_services']);
        $this->assertSame(0.0, (float) $data['services_total']);
    }

    /**
     * APP-3: البحث يطابق رقم الطلب (uuid) حتى يفتح الرابط الذكي الطلب الصحيح،
     * ويرجع مصفوفة فارغة (لا null) عند عدم وجود نتائج.
     */
    public function test_search_matches_order_uuid_and_returns_array_when_empty(): void
    {
        $user = $this->customer();
        $contract = $this->paidHousingContract($user, ['instrument_type' => 'electronic']);
        $order = (string) $contract->uuid; // رقم الطلب الفعلي (يُولَّد عند الإنشاء)

        $hit = $this->getJson('/api/v2/search/'.$order)->assertOk()->json('data');
        $this->assertNotEmpty($hit);

        // مع سابقة #
        $this->getJson('/api/v2/search/'.urlencode('#'.$order))->assertOk()
            ->assertJsonCount(1, 'data');

        // رقم غير موجود → مصفوفة فارغة لا null.
        $this->getJson('/api/v2/search/000000')->assertOk()->assertExactJson([
            'message' => trans('api.success'), 'code' => 200, 'success' => true, 'data' => [],
        ]);
    }

    public function test_duration_labels(): void
    {
        $this->assertSame('سنة', ContractInvoiceService::durationLabel(12));
        $this->assertSame('سنتين', ContractInvoiceService::durationLabel(24));
        $this->assertSame('3 سنوات', ContractInvoiceService::durationLabel(36));
        $this->assertSame('سنة و3 أشهر', ContractInvoiceService::durationLabel(15));
        $this->assertSame('6 أشهر', ContractInvoiceService::durationLabel(6));
        $this->assertSame('', ContractInvoiceService::durationLabel(0));
    }
}
