<?php

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Setting;
use App\Support\SupportContact;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * دفعة الإصلاحات (ب) — رقم الدعم من الإعدادات، إصدار التطبيق، نقطة الصحة، وكاش النقاط العامة.
 */
class FixBatchBSettingsHealthTest extends TestCase
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
        Artisan::call('db:seed', ['--class' => 'RoleSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'PermissionSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'ContractPeriodSeeder', '--force' => true]);
        Cache::flush();
    }

    private function admin(): Employee
    {
        $role = Role::query()->where('name', 'admin')->firstOrFail();
        $employee = Employee::query()->create([
            'name' => 'مدير', 'email' => 'admin'.uniqid().'@test.local', 'password' => Hash::make('secret'),
            'is_active' => true, 'role_id' => $role->id, 'role' => $role->name,
        ]);
        Sanctum::actingAs($employee);

        return $employee;
    }

    public function test_settings_expose_support_number_with_official_fallback(): void
    {
        // بدون صف إعدادات: الرقم الرسمي الاحتياطي.
        $this->getJson('/api/v2/settings')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertJsonPath('data.whatsapp_contact', '966597500014')
            ->assertJsonPath('data.whatsapp', '966597500014')
            ->assertJsonPath('data.support_phone', '966597500014')
            ->assertJsonPath('data.support_phone_local', '0597500014');

        // قيمة محلية في الإعدادات تُطبَّع إلى الصيغة الدولية.
        Setting::query()->create(['whatsapp_contact' => '0551112222', 'whatsapp' => '', 'instagram' => 'https://instagram.com/x']);
        \App\Support\PublicCache::flush();

        $this->getJson('/api/v2/settings')
            ->assertOk()
            ->assertJsonPath('data.whatsapp_contact', '966551112222')
            ->assertJsonPath('data.support_phone', '966551112222')
            ->assertJsonPath('data.social.instagram', 'https://instagram.com/x');

        $this->assertSame('966597500014', SupportContact::normalize('+966 59 750 0014'));
        $this->assertSame('966597500014', SupportContact::normalize('0597500014'));
        $this->assertNull(SupportContact::normalize('12345'));
    }

    public function test_app_version_endpoint_and_admin_settings_round_trip(): void
    {
        $this->getJson('/api/v2/app/version')
            ->assertOk()
            ->assertJsonPath('data.ios.min_version', '2.1.0')
            ->assertJsonPath('data.android.min_version', '2.1.0')
            ->assertJsonPath('data.ios.force_update', false);

        $this->admin();

        $this->postJson('/api/admin/settings', [
            'app_ios_min_version' => '2.2.0',
            'app_ios_latest_version' => '2.3.1',
            'app_ios_store_url' => 'https://apps.apple.com/sa/app/id123',
            'app_android_min_version' => '2.1.5',
            'app_android_store_url' => 'https://play.google.com/store/apps/details?id=com.aqdi',
            'app_force_update_message' => 'حدّث التطبيق من فضلك',
            'whatsapp_contact' => '0597500014',
        ])->assertOk()
            ->assertJsonPath('data.app_version.app_ios_min_version', '2.2.0')
            ->assertJsonPath('data.app_version.app_android_store_url', 'https://play.google.com/store/apps/details?id=com.aqdi')
            ->assertJsonPath('data.social.whatsapp_contact', '966597500014')
            ->assertJsonPath('data.support.whatsapp', '966597500014');

        $this->getJson('/api/v2/app/version')
            ->assertOk()
            ->assertJsonPath('data.ios.min_version', '2.2.0')
            ->assertJsonPath('data.ios.latest_version', '2.3.1')
            ->assertJsonPath('data.ios.store_url', 'https://apps.apple.com/sa/app/id123')
            ->assertJsonPath('data.android.min_version', '2.1.5')
            ->assertJsonPath('data.force_update_message', 'حدّث التطبيق من فضلك');

        // /app-status يقرأ نفس المصدر.
        $this->getJson('/api/v2/app-status?platform=ios&current_version=2.1.0')
            ->assertOk()
            ->assertJsonPath('data.update.force_update', true);
    }

    public function test_health_reports_db_and_scheduler_heartbeat(): void
    {
        Artisan::call('db:seed', ['--class' => 'SettingContractSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'RegionSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'CitySeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'PaymentTypeSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'UnitTypeSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'UnitUsageSeeder', '--force' => true]);

        $response = $this->getJson('/api/v2/health');
        $data = $response->json();
        $this->assertArrayHasKey('time', $data);
        $this->assertSame('ok', $data['db']);
        $this->assertNull($data['scheduler_last_run']);

        Cache::put('scheduler.last_run', now()->subMinutes(2)->toIso8601String());
        $this->getJson('/api/v2/health')->assertJsonPath('scheduler_stale', false);

        Cache::put('scheduler.last_run', now()->subHours(3)->toIso8601String());
        $this->getJson('/api/v2/health')->assertJsonPath('scheduler_stale', true);
    }

    public function test_public_endpoints_are_cached_and_flushed_on_settings_save(): void
    {
        Setting::query()->create(['whatsapp' => '966500000000']);
        \App\Support\PublicCache::flush();

        $this->getJson('/api/v2/pricing')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertJsonPath('data.housing.first_year', 249);

        // تغيير مباشر في القاعدة (بدون أحداث الموديل) لا يظهر — الكاش 10 دقائق...
        Setting::query()->update(['doc_fee_housing_first_year' => 300]);
        $this->getJson('/api/v2/pricing')->assertJsonPath('data.housing.first_year', 249);

        // ...إلا بعد تفريغ الكاش (DocFee::flushSettingsCache يفرّغ كاش النقاط العامة أيضاً).
        \App\Support\DocFee::flushSettingsCache();
        $this->getJson('/api/v2/pricing')->assertJsonPath('data.housing.first_year', 300);

        // الحفظ من اللوحة يفرّغ الكاش تلقائياً.
        $this->admin();
        $this->postJson('/api/admin/settings', ['doc_fee_housing_first_year' => 310])->assertOk();
        $this->getJson('/api/v2/pricing')->assertJsonPath('data.housing.first_year', 310);

        $this->getJson('/api/v2/contract-periods?contract_type=housing')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v2/coupons/available')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertJsonPath('data.available', false);
    }
}
