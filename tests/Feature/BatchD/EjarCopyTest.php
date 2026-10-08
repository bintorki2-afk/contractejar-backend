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
        $this->assertSame('19/10/1410 هـ — 1990-05-15 م', $tenant['tenant_dob']['value']);
        $this->assertSame('440123', collect($data['blocks'][2]['fields'])->firstWhere('key', 'instrument_number')['value']);
        $this->assertSame('30000 ر.س', collect($data['blocks'][4]['fields'])->firstWhere('key', 'annual_rent_amount_for_the_unit')['value']);
        $this->assertStringContainsString('E-1 — باسم المستأجر', $data['blocks'][6]['text']);
        $this->assertStringStartsWith('— بيانات المؤجر —', $data['text']);
        $this->assertDoesNotMatchRegularExpression('/[٠-٩]/u', $data['text']);

        $plain = $this->get('/api/admin/orders/'.$contract->id.'/ejar-copy?format=text')->assertOk();
        $this->assertStringContainsString('text/plain', (string) $plain->headers->get('Content-Type'));
        $this->assertStringContainsString('رقم هوية المستأجر: 1098765432', $plain->getContent());
    }
}
