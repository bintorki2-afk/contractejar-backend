<?php

namespace Tests\Feature\BatchD;

use App\Support\FieldMapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * دفعة (د) — ب24: كل مُدخل من الخطوات (حسب FieldMapping) يظهر في تفاصيل الطلب باللوحة بنفس القيمة،
 * ومفاتيح العميل المذكورة تظهر في GET /api/v2/contracts/{id}، والملف docs/field-mapping.md محدّث.
 */
class FieldMappingReflectionTest extends BatchDTestCase
{
    /** @return array<string, int> */
    private function references(): array
    {
        $now = now();
        $regionId = DB::table('regions')->insertGetId(['name_ar' => 'الرياض', 'name_en' => 'Riyadh', 'created_at' => $now, 'updated_at' => $now]);
        $cityId = DB::table('cities')->insertGetId(['name_ar' => 'الرياض', 'name_en' => 'Riyadh', 'region_id' => $regionId, 'created_at' => $now, 'updated_at' => $now]);
        foreach (['ReaEstatTypeSeeder', 'ReaEstatUsageSeeder', 'UnitTypeSeeder', 'UnitUsageSeeder', 'PaymentTypeSeeder', 'ContractPeriodSeeder'] as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        return [
            'region_id' => $regionId, 'city_id' => $cityId,
            'property_type_id' => (int) DB::table('rea_estat_types')->value('id'),
            'property_usage_id' => (int) DB::table('rea_estat_usages')->value('id'),
            'unit_type_id' => (int) DB::table('unit_types')->value('id'),
            'unit_usage_id' => (int) DB::table('unit_usages')->value('id'),
            'payment_type_id' => (int) DB::table('payment_types')->value('id'),
            'period_id' => (int) DB::table('contract_periods')->where('contract_type', 'housing')->value('id'),
        ];
    }

    private function resolve(mixed $value, array $ref): mixed
    {
        return is_string($value) && str_starts_with($value, '@') ? $ref[substr($value, 1)] : $value;
    }

    private function param(array $row): string
    {
        preg_match('/^([A-Za-z_\[\]\.]+)/', $row['input'], $m);
        $param = $m[1];
        if (is_array($row['fixture']) && isset($row['fixture']['day'])) {
            $param = preg_replace('/_day$/', '', $param);
        }

        return $param;
    }

    public function test_every_step_input_reaches_the_admin_order_detail(): void
    {
        $ref = $this->references();
        $user = $this->customer();
        Sanctum::actingAs($user);

        $payloads = ['start' => [], 'step1' => [], 'step2' => [], 'step3' => [], 'step4' => [], 'step5' => ['units' => [[]]], 'step6' => ['conditions' => false]];
        $checked = [];
        foreach (FieldMapping::CONTRACT as $row) {
            if ($row['fixture'] === null) {
                continue;
            }
            $param = $this->param($row);
            $fixture = $this->resolve($row['fixture'], $ref);
            if (str_starts_with($param, 'units[].')) {
                $payloads['step5']['units'][0][substr($param, 8)] = $fixture;
            } elseif (is_array($fixture) && isset($fixture['day'])) {
                foreach ($fixture as $part => $v) {
                    $payloads[$row['step']][$param.'_'.$part] = $v;
                }
            } elseif ($param === 'conditions') {
                $payloads['step6']['conditions'] = true;
                $payloads['step6']['other_conditions_list'] = $fixture;
            } else {
                $payloads[$row['step']][$param] = $fixture;
            }
            $checked[] = $row;
        }

        $id = $this->postJson('/api/v2/contract/start', $payloads['start'])->assertOk()->json('data.contract_id');
        $responses = [];
        foreach (['step1', 'step2', 'step3', 'step4', 'step5', 'step6'] as $step) {
            $responses[$step] = $this->postJson('/api/v2/contract/'.$step, array_merge(['id' => $id], $payloads[$step]))->assertOk()->json('data');
        }
        // إصلاح: تقسيم تاريخ البداية الميلادي YYYY-MM-DD كان معكوساً في رد الخطوة 6 (اليوم = 2026).
        $this->assertSame(['01', '11', '2026'], [
            $responses['step6']['contract_starting_date_day'], $responses['step6']['contract_starting_date_month'], $responses['step6']['contract_starting_date_year'],
        ]);

        $customer = $this->getJson('/api/v2/contracts/'.$id)->assertOk()->json('data');
        $this->employee('admin');
        $admin = $this->getJson('/api/admin/orders/'.$id)->assertOk()->json('data');

        $this->assertGreaterThanOrEqual(50, count($checked));
        foreach ($checked as $row) {
            $expected = $this->resolve($row['expected'], $ref);
            $actual = data_get($admin, $row['admin'], '__missing__');
            $this->assertNotSame('__missing__', $actual, "admin key [{$row['admin']}] missing for input [{$row['input']}]");
            $this->assertEquals($expected, $actual, "admin [{$row['admin']}] value for input [{$row['input']}]");

            if ($row['customer'] !== null) {
                $this->assertNotSame('__missing__', data_get($customer, $row['customer'], '__missing__'), "customer key [{$row['customer']}] missing");
            }
        }
    }

    public function test_doc_is_up_to_date(): void
    {
        $this->assertSame(FieldMapping::markdown(), file_get_contents(base_path('docs/field-mapping.md')), 'run: php artisan docs:field-mapping');
    }
}
