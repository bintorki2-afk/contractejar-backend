<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * فحص (CROSS-11): ترحيل أسعار 2026-10-08 قابل لإعادة التشغيل بعد فشل جزئي (حراسات hasColumn)،
 * و railway-start.sh يرفض الخدمة في الإنتاج إذا فشل migrate.
 */
class MigrationIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false]);
        DB::purge('sqlite'); DB::setDefaultConnection('sqlite'); DB::reconnect('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_pricing_migration_up_can_run_again_on_an_already_migrated_schema(): void
    {
        $migration = require database_path('migrations/2026_10_08_000100_batch_pricing_meters_periods_and_social.php');

        // مثل إعادة تشغيل بعد فشل جزئي: الأعمدة موجودة مسبقاً.
        $migration->up();
        $migration->up();

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('settings', 'lessor_change_fee'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('real_estates', 'source_contract_id'));
    }

    public function test_railway_start_refuses_to_serve_when_migrate_fails_in_production(): void
    {
        $script = file_get_contents(base_path('railway-start.sh'));

        $this->assertStringNotContainsString('migrate --force || echo', $script);
        $this->assertMatchesRegularExpression('/if ! php artisan migrate --force; then.*APP_ENV" = "production".*exit 1/s', $script);
        $this->assertSame('/api/v2/health', json_decode(file_get_contents(base_path('railway.json')), true)['deploy']['healthcheckPath'] ?? null);
    }
}
