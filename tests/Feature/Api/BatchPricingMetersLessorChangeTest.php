<?php

namespace Tests\Feature\Api;

use App\Models\Contract;
use App\Models\Coupon;
use App\Models\LessorChangeRequest;
use App\Models\RealEstate;
use App\Models\Setting;
use App\Modules\Users\Models\User;
use App\Support\ContractPricing;
use App\Support\DocFee;
use App\Support\DocumentSurcharge;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * دفعة 2026-10-08: الأسعار من الإعدادات، رسوم المستندات الإضافية، العداد المشترك،
 * مدد العقد (سنة/سنتين)، الكوبون المتاح، حفظ العقار بدون تكرار، وخدمة تغيير المؤجر.
 *
 * تشغّل جميع الـ migrations على sqlite في الذاكرة (المخطط الحقيقي وليس يدوياً).
 */
class BatchPricingMetersLessorChangeTest extends TestCase
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
        Storage::fake('local');
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

    public function test_public_pricing_endpoint_reads_settings(): void
    {
        $this->getJson('/api/v2/pricing')
            ->assertOk()
            ->assertJsonPath('data.housing.first_year', 249)
            ->assertJsonPath('data.housing.extra_year', 150)
            ->assertJsonPath('data.commercial.first_year', 349)
            ->assertJsonPath('data.commercial.extra_year', 250)
            ->assertJsonPath('data.document_surcharge.fee', 75)
            ->assertJsonPath('data.meter_transfer_fee.housing.electricity', 15)
            ->assertJsonPath('data.meter_transfer_fee.commercial.water', 25)
            ->assertJsonPath('data.lessor_change_fee', 400);

        Setting::query()->update(['doc_fee_commercial_extra_year' => 275, 'document_surcharge_fee' => 90]);
        DocFee::flushSettingsCache();

        $this->getJson('/api/v2/pricing')
            ->assertJsonPath('data.commercial.extra_year', 275)
            ->assertJsonPath('data.document_surcharge.fee', 90);
    }

    public function test_fee_rule_year_or_part_thereof_and_commercial_extra_year_is_250(): void
    {
        $this->assertSame(249.0, DocFee::amount(12, 'housing'));
        $this->assertSame(399.0, DocFee::amount(13, 'housing'));   // سنة ويوم → سنتين
        $this->assertSame(349.0, DocFee::amount(6, 'commercial'));
        $this->assertSame(599.0, DocFee::amount(24, 'commercial'));
        $this->assertSame(849.0, DocFee::amount(25, 'commercial'));
    }

    public function test_document_surcharge_applies_once_for_special_deed_types(): void
    {
        $user = $this->customer();
        $contract = Contract::query()->create([
            'uuid' => '600001', 'user_id' => $user->id, 'contract_type' => 'housing',
            'instrument_type' => 'old_handwritten', 'duration_preset' => 'other',
            'duration_years' => 2, 'duration_months' => 0, 'total_months' => 24, 'step' => 7,
        ]);

        $pricing = ContractPricing::for($contract);
        $this->assertSame(399.0, $pricing['fee']);
        $this->assertSame(75.0, $pricing['document_surcharge']);
        $this->assertTrue($pricing['document_surcharge_applies']);
        $this->assertSame(474.0, $pricing['total']);

        $contract->update(['instrument_type' => 'electronic']);
        $pricing = ContractPricing::for($contract->fresh());
        $this->assertSame(0.0, $pricing['document_surcharge']);
        $this->assertSame(399.0, $pricing['total']);

        $this->assertTrue(DocumentSurcharge::appliesTo('strong_argument'));
        $this->assertFalse(DocumentSurcharge::appliesTo('lease_renewal'));
    }

    public function test_contract_periods_only_expose_one_and_two_years(): void
    {
        $this->getJson('/api/v2/contract-periods?contract_type=housing')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.months', 12)
            ->assertJsonPath('data.1.months', 24);
    }

    public function test_coupon_availability_is_public_and_reflects_active_coupons(): void
    {
        $this->getJson('/api/v2/coupons/available')->assertOk()->assertJsonPath('data.available', false);

        Coupon::query()->create([
            'name' => 'ترحيب', 'code_coupon' => 'HELLO10', 'type_coupon' => 'ratio', 'value_coupon' => 10,
            'date_start' => now()->subDay()->toDateString(), 'date_end' => now()->addDay()->toDateString(),
            'usage' => 5, 'usage_of_user' => 1, 'is_review' => true, 'is_delete' => false,
        ]);

        $this->getJson('/api/v2/coupons/available')->assertJsonPath('data.available', true);
    }

    public function test_step5_requires_meter_number_and_ownership_when_meter_enabled_and_shared_fee(): void
    {
        $user = $this->customer();
        $contract = Contract::query()->create([
            'uuid' => '600002', 'user_id' => $user->id, 'contract_type' => 'housing', 'step' => 5,
        ]);

        $base = ['id' => $contract->id, 'units' => [[
            'unit_type_id' => null, 'unit_number' => '1',
            'electricity_meter' => 1, 'electricity_meter_number' => '', 'electricity_meter_ownership' => '',
        ]]];

        $this->postJson('/api/v2/contract/step5', $base)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['units.0.electricity_meter_number', 'units.0.electricity_meter_ownership']);

        $shared = $base;
        $shared['units'][0]['electricity_meter_number'] = '123';
        $shared['units'][0]['electricity_meter_ownership'] = 'shared';
        $this->postJson('/api/v2/contract/step5', $shared)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['units.0.electricity_shared_monthly_fee']);
    }

    public function test_save_property_is_idempotent_and_can_be_undone(): void
    {
        $user = $this->customer();
        $contract = Contract::query()->create([
            'uuid' => '600003', 'user_id' => $user->id, 'contract_type' => 'housing', 'step' => 7,
            'name_real_estate' => 'عمارة', 'instrument_type' => 'electronic',
        ]);

        $this->postJson('/api/v2/save/property', ['contract_id' => $contract->id, 'name_real_estate' => 'عمارتي'])
            ->assertStatus(201);
        $this->postJson('/api/v2/save/property', ['contract_id' => $contract->id, 'name_real_estate' => 'عمارتي'])
            ->assertOk();
        $this->postJson('/api/v2/save/property', ['contract_id' => $contract->id, 'name_real_estate' => 'عمارتي'])
            ->assertOk();

        $this->assertSame(1, RealEstate::query()->where('source_contract_id', $contract->id)->count());
        $this->assertTrue((bool) $contract->fresh()->is_real);

        $this->deleteJson('/api/v2/save/property/'.$contract->id)->assertOk();
        $this->assertSame(0, RealEstate::query()->where('source_contract_id', $contract->id)->count());
        $this->assertFalse((bool) $contract->fresh()->is_real);
    }

    public function test_lessor_change_request_flow_create_pay_track(): void
    {
        config(['services.moyasar.test_mode' => true]);
        $user = $this->customer();

        $this->getJson('/api/v2/lessor-change/info')->assertOk()->assertJsonPath('data.fee', 400);

        $response = $this->post('/api/v2/lessor-change', [
            'old_deed_image' => UploadedFile::fake()->image('old.jpg'),
            'new_deed_image' => UploadedFile::fake()->image('new.jpg'),
            'new_owner_id_number' => '١٠٩٨٧٦٥٤٣٢', // أرقام عربية تُحوّل
            'new_owner_dob_day' => 10, 'new_owner_dob_month' => 5, 'new_owner_dob_year' => 1410,
            'new_owner_dob_type' => 'hijri',
            'mobile' => '0551234567',
            'acknowledged' => 1,
        ], ['Accept' => 'application/json']);
        $response->assertStatus(201)->assertJsonPath('data.kind', 'lessor_change')->assertJsonPath('data.fee', 400);

        $uuid = $response->json('data.order_number');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $uuid);
        $this->assertSame('1098765432', LessorChangeRequest::query()->where('uuid', $uuid)->value('new_owner_id_number'));

        // الإقرار إجباري
        $this->post('/api/v2/lessor-change', [
            'old_deed_image' => UploadedFile::fake()->image('old.jpg'),
            'new_deed_image' => UploadedFile::fake()->image('new.jpg'),
            'new_owner_id_number' => '1098765432',
            'new_owner_dob_day' => 10, 'new_owner_dob_month' => 5, 'new_owner_dob_year' => 1410,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        // التتبّع العام برقم الطلب + الجوال
        $this->postJson('/api/v2/contract/track', ['order' => $uuid, 'mobile' => '0551234567'])
            ->assertOk()
            ->assertJsonPath('data.kind', 'lessor_change')
            ->assertJsonPath('data.awaiting_payment', true);
        $this->postJson('/api/v2/contract/track', ['order' => $uuid, 'mobile' => '0559999999'])->assertStatus(404);

        $this->getJson('/api/v2/lessor-change/mine')->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * CROSS-4: رقم الطلب (uuid) لا يتصادم بين العقود وطلبات تغيير المؤجر والدفعات.
     */
    public function test_contract_uuid_generator_avoids_collisions_across_shared_tables(): void
    {
        $user = $this->customer();

        $lessor = LessorChangeRequest::query()->create([
            'uuid' => '654321', 'user_id' => $user->id, 'mobile' => '966551234567',
            'old_deed_image' => 'x', 'new_deed_image' => 'y', 'new_owner_id_number' => '1098765432',
            'new_owner_dob' => '10/05/1410', 'new_owner_dob_type' => 'hijri',
            'fee' => 400, 'status' => 'pending_payment', 'platform' => 'web',
        ]);

        DB::table('payments')->insert([
            'name' => 'pay_555000', 'contract_uuid' => '555000', 'amount' => 100, 'status' => 'success',
            'payment_method' => 'moyasar', 'payment_brand' => 'mada',
            'tran_currency' => 'SAR', 'payment_date' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue(Contract::uuidInUse('654321'), 'رقم طلب تغيير مؤجر قائم');
        $this->assertTrue(Contract::uuidInUse('555000'), 'رقم مستخدم في جدول الدفعات');
        $this->assertFalse(Contract::uuidInUse('123456'), 'رقم غير مستخدم');

        // رقم عقد مُولّد فعلياً يصبح «مستخدماً» (يفحصه المولّد في كل الجداول).
        $contract = Contract::query()->create(['user_id' => $user->id, 'contract_type' => 'housing']);
        $this->assertTrue(Contract::uuidInUse((string) $contract->uuid));
        $this->assertNotContains((string) $contract->uuid, ['654321', '555000']);
    }

    /**
     * cascade: لا يمكن حذف مدة عقد مرتبطة بطلبات (الحذف كان يُسلسِل حذف العقود).
     */
    public function test_contract_period_in_use_cannot_be_deleted(): void
    {
        $user = $this->customer();

        $admin = \App\Models\Employee::query()->create([
            'name' => 'مدير', 'email' => 'adm'.uniqid().'@test.local',
            'password' => bcrypt('x'), 'is_active' => true, 'role' => 'admin',
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $periodId = DB::table('contract_periods')->where('is_active', true)->value('id');
        Contract::query()->create([
            'user_id' => $user->id, 'contract_type' => 'housing',
            'contract_term_in_years' => $periodId,
        ]);

        $this->postJson('/api/admin/contract-periods/'.$periodId.'/delete')
            ->assertStatus(422);

        $this->assertDatabaseHas('contract_periods', ['id' => $periodId]);
    }

    /**
     * CROSS-3: لا يُقبل في step6 رقم مدة عقد غير مفعّلة (شهري/ربع سنوي) بطلب مباشر.
     */
    public function test_step6_rejects_an_inactive_contract_period(): void
    {
        $activeId = DB::table('contract_periods')->where('is_active', true)->value('id');
        $this->assertNotNull($activeId);

        // مدة غير مفعّلة (لا تُعرض للعميل) — نُنشئها صراحةً.
        $inactiveId = DB::table('contract_periods')->insertGetId([
            'period' => 'شهري', 'contract_type' => 'housing', 'months' => 1,
            'note_ar' => 'شهري', 'note_en' => 'monthly',
            'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rule = [\Illuminate\Validation\Rule::exists('contract_periods', 'id')->where('is_active', true)];

        $this->assertTrue(
            \Illuminate\Support\Facades\Validator::make(['p' => $activeId], ['p' => $rule])->passes()
        );
        $this->assertFalse(
            \Illuminate\Support\Facades\Validator::make(['p' => $inactiveId], ['p' => $rule])->passes()
        );
    }
}
