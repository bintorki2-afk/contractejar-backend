<?php

namespace Tests\Feature\BatchD;

use App\Models\Contract;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * دفعة (د) — ب19: qa:daily-smoke — مسار العميل كاملاً داخلياً ثم حذف البيانات الاصطناعية + تيليجرام.
 */
class DailySmokeTest extends BatchDTestCase
{
    private function seedReferences(): void
    {
        $now = now();
        $regionId = DB::table('regions')->insertGetId(['name_ar' => 'الرياض', 'name_en' => 'Riyadh', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('cities')->insert(['name_ar' => 'الرياض', 'name_en' => 'Riyadh', 'region_id' => $regionId, 'created_at' => $now, 'updated_at' => $now]);
        foreach (['rea_estat_types', 'rea_estat_usages', 'unit_types', 'unit_usages', 'payment_types'] as $table) {
            if (DB::table($table)->count() === 0) {
                Artisan::call('db:seed', ['--class' => match ($table) {
                    'rea_estat_types' => 'ReaEstatTypeSeeder', 'rea_estat_usages' => 'ReaEstatUsageSeeder',
                    'unit_types' => 'UnitTypeSeeder', 'unit_usages' => 'UnitUsageSeeder', 'payment_types' => 'PaymentTypeSeeder',
                }, '--force' => true]);
            }
        }
        Artisan::call('db:seed', ['--class' => 'ContractPeriodSeeder', '--force' => true]);
    }

    public function test_smoke_passes_cleans_up_and_reports(): void
    {
        $this->seedReferences();
        config(['services.telegram.bot_token' => 't', 'services.telegram.chat_id' => '1']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $contractsBefore = Contract::query()->count();
        $usersBefore = DB::table('users')->count();

        $code = Artisan::call('qa:daily-smoke', ['--in-process' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('✅ كل شي سليم', $output);
        $this->assertSame($contractsBefore, Contract::query()->count());
        $this->assertSame($usersBefore, DB::table('users')->count());
        Http::assertSent(fn ($r) => str_contains((string) ($r->data()['text'] ?? ''), '✅ كل شي سليم'));
    }

    public function test_failure_is_reported_with_the_step(): void
    {
        // بلا بيانات مرجعية ⇒ تفشل خطوة.
        config(['services.telegram.bot_token' => 't', 'services.telegram.chat_id' => '1']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        DB::table('payment_types')->delete();

        $code = Artisan::call('qa:daily-smoke', ['--in-process' => true]);
        $this->assertSame(1, $code);
        Http::assertSent(fn ($r) => str_contains((string) ($r->data()['text'] ?? ''), '❌ عطل'));
        $this->assertSame(0, Contract::query()->where('is_synthetic', true)->count());
    }

    public function test_synthetic_orders_are_excluded_from_lists_and_reports(): void
    {
        $this->employee('admin');
        $real = $this->paidContract();
        $fake = $this->paidContract();
        $fake->forceFill(['is_synthetic' => true])->save();

        $ids = collect($this->getJson('/api/admin/orders?per_page=100')->json('data.items'))->pluck('id')->all();
        $this->assertContains($real->id, $ids);
        $this->assertNotContains($fake->id, $ids);
        $this->assertSame(1, $this->getJson('/api/admin/orders/status-counts')->json('data.all'));
        $this->assertSame(1, $this->getJson('/api/admin/reports/performance?period=all')->json('data.funnel_summary.started'));
    }
}
