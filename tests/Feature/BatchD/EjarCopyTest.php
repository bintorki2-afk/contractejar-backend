<?php

namespace Tests\Feature\BatchD;

use App\Models\UnitsReal;
use Illuminate\Support\Facades\DB;

/**
 * دفعة (د) — ب15: «نسخ بيانات إيجار» — كتل بترتيب إيجار، أرقام لاتينية، هجري + ميلادي.
 */
class EjarCopyTest extends BatchDTestCase
{
    public function test_blocks_order_ascii_digits_and_dual_dates(): void
    {
        $this->employee('manager');
        $contract = $this->paidContract([
            'name_owner' => 'سعد', 'property_owner_id_num' => '١٠٢٣٤٥٦٧٨٩', 'property_owner_dob' => '10-05-1410', 'type_dob_property_owner' => 'hijri',
            'tenant_id_num' => '1098765432', 'tenant_dob' => '1990-05-15', 'type_tenant_dob' => 'gregorian', 'tenant_mobile' => '0551234567',
            'instrument_number' => '٤٤٠١٢٣', 'neighborhood' => 'النرجس', 'annual_rent_amount_for_the_unit' => 30000,
            'contract_starting_date' => '2026-11-01', 'type_contract_starting_date' => 'gregorian',
        ]);
        $unit = UnitsReal::query()->create(['user_id' => $contract->user_id, 'unit_number' => '12', 'unit_area' => 150, 'electricity_meter_number' => 'E-1', 'electricity_meter_ownership' => 'tenant']);
        DB::table('contract_units')->insert(['contract_id' => $contract->id, 'real_unit_id' => $unit->id, 'created_at' => now(), 'updated_at' => now()]);

        $data = $this->getJson('/api/admin/orders/'.$contract->id.'/ejar-copy')->assertOk()->json('data');

        $this->assertSame(['lessor', 'tenant', 'property', 'unit_1', 'financial', 'dates', 'meters'], array_column($data['blocks'], 'key'));
        $lessor = collect($data['blocks'][0]['fields'])->keyBy('key');
        $this->assertSame('1023456789', $lessor['property_owner_id_num']['value']);
        $this->assertSame('10/05/1410 هـ — 1989-12-09 م', $lessor['property_owner_dob']['value']);
        $tenant = collect($data['blocks'][1]['fields'])->keyBy('key');
        $this->assertSame('20/10/1410 هـ — 1990-05-15 م', $tenant['tenant_dob']['value']);
        $this->assertSame('440123', collect($data['blocks'][2]['fields'])->firstWhere('key', 'instrument_number')['value']);
        $this->assertSame('30000 ر.س', collect($data['blocks'][4]['fields'])->firstWhere('key', 'annual_rent_amount_for_the_unit')['value']);
        $this->assertStringContainsString('E-1 — باسم المستأجر', $data['blocks'][6]['text']);
        $this->assertStringStartsWith('— بيانات المؤجر —', $data['text']);
        $this->assertDoesNotMatchRegularExpression('/[٠-٩]/u', $data['text']);

        $plain = $this->get('/api/admin/orders/'.$contract->id.'/ejar-copy?format=text')->assertOk();
        $this->assertStringContainsString('text/plain', (string) $plain->headers->get('Content-Type'));
        $this->assertStringContainsString('رقم هوية المستأجر: 1098765432', $plain->getContent());
    }

    public function test_qa_fixes_tenant_type_representative_terms_address_floor(): void
    {
        $this->employee('manager');
        $this->assertTrue(DB::table('tenant_roles')->whereIn('id', [1, 6])->count() === 2, 'tenant roles seeded by migration');

        $person = $this->paidContract(['tenant_entity' => 'person', 'tenant_id_num' => '1098765432', 'tenant_role_ids' => [1, 6], 'tenant_role_values' => ['6' => '2000'],
            'other_conditions_list' => ['لا يسمح بالحيوانات'], 'image_address' => 'images/a.png', 'address_url' => 'https://maps.example/x']);
        $unit = UnitsReal::query()->create(['user_id' => $person->user_id, 'unit_number' => '1', 'floor_number' => '0']);
        DB::table('contract_units')->insert(['contract_id' => $person->id, 'real_unit_id' => $unit->id, 'created_at' => now(), 'updated_at' => now()]);

        $text = $this->getJson('/api/admin/orders/'.$person->id.'/ejar-copy')->assertOk()->json('data.text');
        $this->assertStringContainsString('نوع المستأجر: فرد', $text);
        $this->assertStringNotContainsString('منشأة: نعم', $text);
        $this->assertStringNotContainsString('صفة المستأجر', $text);
        $this->assertStringContainsString('— الشروط والالتزامات —', $text);
        $this->assertStringContainsString('يحق للمستاجر التاجير من الباطن', $text);
        $this->assertStringContainsString('مبلغ الضمان: 2000 ر.س', $text);
        $this->assertStringContainsString('شرط إضافي 1: لا يسمح بالحيوانات', $text);
        $this->assertStringContainsString('رابط الموقع: https://maps.example/x', $text);
        $this->assertStringContainsString('مرفق صورة العنوان الوطني', $text);
        $this->assertStringContainsString('رقم الدور: أرضي', $text);

        $inst = $this->paidContract(['tenant_entity' => 'institution', 'tenant_entity_unified_registry_number' => '7001234567', 'authorization_type' => 'owner_and_representative_of_record',
            'id_num_of_property_tenant_agent' => '1011111111', 'mobile_of_property_tenant_agent' => '551112222', 'dob_of_property_tenant_agent' => '01-01-1400', 'type_dob_tenant_agent' => 'hijri']);
        $data = $this->getJson('/api/admin/orders/'.$inst->id.'/ejar-copy')->assertOk()->json('data');
        $this->assertContains('tenant_representative', array_column($data['blocks'], 'key'));
        $this->assertStringContainsString('نوع المستأجر: منشأة', $data['text']);
        $this->assertStringContainsString('صفة ممثل المنشأة: مالك السجل وممثله', $data['text']);
        $this->assertStringContainsString('رقم الهوية: 1011111111', $data['text']);
        $this->assertStringContainsString('الجوال: 0551112222', $data['text']);
        $this->assertStringContainsString('01/01/1400 هـ', $data['text']);
    }

    public function test_umm_al_qura_conversion(): void
    {
        $this->assertTrue(\App\Support\HijriDate::umAlQuraAvailable());
        // أم القرى: 1 جمادى الأولى 1448 = 2026-10-12 (الحسابي كان 13).
        $this->assertSame('2026-10-12', \App\Support\HijriDate::toGregorian(1448, 5, 1)->format('Y-m-d'));
        $this->assertSame([1448, 5, 21], \App\Support\HijriDate::fromGregorian(\Illuminate\Support\Carbon::parse('2026-11-01')));
    }
}
