<?php

namespace Tests\Feature\BatchD;

use App\Models\Setting;
use App\Support\DocFee;
use Illuminate\Support\Facades\DB;

/**
 * دفعة (د) — قرار المالك: السنة الإضافية للعقد التجاري 450، والترحيل يحدّث الصف فقط إذا كان 250.
 */
class CommercialExtraYearTest extends BatchDTestCase
{
    public function test_default_and_pricing_fallback_are_450(): void
    {
        $this->assertSame(450.0, DocFee::COMMERCIAL_EXTRA_YEAR);
        $this->getJson('/api/v2/pricing')->assertOk()->assertJsonPath('data.commercial.extra_year', 450);
    }

    public function test_migration_updates_only_rows_still_at_250(): void
    {
        $migration = require database_path('migrations/2026_10_09_000600_batch_d_settings_commercial_450_and_auto_assign.php');

        DB::table('settings')->update(['doc_fee_commercial_extra_year' => 250]);
        $migration->up();
        $this->assertSame(450.0, (float) Setting::query()->value('doc_fee_commercial_extra_year'));

        DB::table('settings')->update(['doc_fee_commercial_extra_year' => 300]);
        $migration->up();
        $this->assertSame(300.0, (float) Setting::query()->value('doc_fee_commercial_extra_year'));
    }
}
