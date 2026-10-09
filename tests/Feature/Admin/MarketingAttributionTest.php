<?php

namespace Tests\Feature\Admin;

use App\Models\AdSpendDaily;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\MarketingReportsService;
use App\Services\Marketing\AdSpendSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketingAttributionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.url' => 'http://localhost',
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');
        URL::forceRootUrl('http://localhost');

        $this->createMinimalSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'ad_spend_dailies',
            'payments',
            'contracts',
            'contract_statuses',
            'personal_access_tokens',
            'role_permissions',
            'permissions',
            'employees',
            'roles',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_user_keeps_first_touch_utm_from_the_request(): void
    {
        $this->app->instance('request', Request::create('/signup', 'POST', [
            'utm_source' => 'google',
            'utm_campaign' => 'search-lease',
            'utm_term' => 'عقد إيجار إلكتروني',
            'gclid' => 'gclid-1',
        ]));

        $user = User::query()->create([
            'fname' => 'أحمد',
            'mobile' => '0500000001',
            'password' => Hash::make('password'),
        ]);

        $this->assertSame('google', $user->utm_source);
        $this->assertSame('search-lease', $user->utm_campaign);
        $this->assertSame('عقد إيجار إلكتروني', $user->utm_term);
        $this->assertSame('gclid-1', $user->gclid);
        $this->assertNotNull($user->attributed_at);

        $this->app->instance('request', Request::create('/signup', 'POST', [
            'utm_source' => 'tiktok',
        ]));
        $user->refresh();
        app(\App\Services\Marketing\AttributionService::class)->stampUser($user, request());
        $this->assertSame('google', $user->fresh()->utm_source);
    }

    public function test_contract_copies_user_attribution_when_request_has_none(): void
    {
        $user = User::query()->create([
            'fname' => 'سارة',
            'mobile' => '0500000002',
            'password' => Hash::make('password'),
        ]);
        // utm_* are not mass-assignable (production sets them server-side via
        // AttributionService), so persist them the same way before the contract
        // is created and copies the user's first-touch attribution.
        $user->forceFill([
            'utm_source' => 'whatsapp',
            'utm_campaign' => 'wa-organic',
        ])->save();

        $this->app->instance('request', Request::create('/contract/start', 'POST'));

        $contract = Contract::query()->create([
            'user_id' => $user->id,
            'contract_type' => 'housing',
            'step' => 4,
            'is_delete' => 0,
        ]);

        $this->assertSame('whatsapp', $contract->utm_source);
        $this->assertSame('wa-organic', $contract->utm_campaign);
    }

    public function test_marketing_dashboard_computes_cac_conversion_and_roas(): void
    {
        $user = User::query()->create([
            'fname' => 'خالد',
            'mobile' => '0500000003',
            'password' => Hash::make('password'),
        ]);
        // utm_* are not mass-assignable; production sets them server-side via
        // AttributionService. The contract-level attribution is then resolved by
        // joining users, so seeding the user's first touch is enough.
        $user->forceFill([
            'utm_source' => 'google',
            'utm_campaign' => 'Google - Awareness Campaign (Display)',
            'utm_term' => 'عقد إيجار إلكتروني',
        ])->save();

        $paid = Contract::query()->create([
            'user_id' => $user->id,
            'contract_type' => 'housing',
            'step' => 4,
            'is_delete' => 0,
            'is_completed' => 1,
        ]);
        Contract::query()->create([
            'user_id' => $user->id,
            'contract_type' => 'housing',
            'step' => 4,
            'is_delete' => 0,
            'is_completed' => 0,
        ]);

        Payment::query()->create([
            'contract_uuid' => (string) $paid->uuid,
            'amount' => 200,
            'status' => 'success',
        ]);

        AdSpendDaily::query()->create([
            'spent_on' => now()->toDateString(),
            'platform' => 'google',
            'campaign_id' => 'camp-1',
            'campaign_name' => 'Google - Awareness Campaign (Display)',
            'keyword' => '',
            'spend' => 100,
            'currency' => 'SAR',
            'ingest_source' => 'manual',
        ]);

        $filter = app(MarketingReportsService::class)->reportFilterFromPeriodKey('last_30_days');
        $dashboard = app(MarketingReportsService::class)->dashboard($filter);

        $google = collect($dashboard['by_source'])->firstWhere('source', 'google');
        $this->assertNotNull($google);
        $this->assertSame(2, $google['orders']);
        $this->assertSame(1, $google['paid']);
        $this->assertSame(200, $google['revenue']);
        $this->assertSame(100, $google['spend']);
        $this->assertSame(100, $google['cac']);
        $this->assertSame(50, $google['conversion_percent']);

        $this->assertSame('عقد إيجار إلكتروني', $dashboard['top_keywords'][0]['keyword']);
        $this->assertSame(200, $dashboard['top_keywords'][0]['revenue']);

        $this->assertSame('Google - Awareness Campaign (Display)', $dashboard['weakest_campaigns'][0]['campaign']);
        $this->assertSame(2.0, $dashboard['weakest_campaigns'][0]['roas']);
        $this->assertSame(100, $dashboard['weakest_campaigns'][0]['profit']);
    }

    public function test_admin_can_import_spend_and_read_utm_template(): void
    {
        $employee = $this->actingEmployeeWithAnalytics();

        $this->postJson('/api/admin/reports/marketing/spend', [
            'rows' => [[
                'spent_on' => now()->toDateString(),
                'platform' => 'tiktok',
                'campaign_name' => 'TikTok - Conversion',
                'spend' => 9300,
                'currency' => 'SAR',
            ]],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.synced', 1);

        $this->assertSame(1, AdSpendDaily::query()->where('platform', 'tiktok')->count());

        $this->getJson('/api/admin/reports/marketing/utm-template')
            ->assertOk()
            ->assertJsonPath('success', true);

        $body = $this->getJson('/api/admin/reports/marketing?period=last_30_days')->assertOk()->json('data');
        $this->assertArrayHasKey('by_source', $body);
        $this->assertArrayHasKey('accounts', $body);
    }

    public function test_utm_link_command_prints_tagged_url(): void
    {
        $this->artisan('ads:utm-link', [
            'url' => 'https://aqdi.com',
            '--source' => 'google',
            '--campaign' => 'search-lease',
            '--term' => 'عقد إيجار',
        ])->expectsOutputToContain('utm_source=google')
            ->assertSuccessful();
    }

    public function test_credentials_command_lists_unconfigured_platforms(): void
    {
        $this->artisan('ads:credentials')->assertSuccessful();
        $status = app(AdSpendSyncService::class)->credentialStatus();
        $this->assertNotEmpty($status);
        $this->assertFalse($status[0]['configured']);
    }

    public function test_meta_provider_maps_daily_campaign_spend_when_configured(): void
    {
        config([
            'ads.platforms.meta.credentials.access_token' => 'token',
            'ads.platforms.meta.credentials.ad_account_id' => 'act_123',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [[
                    'campaign_id' => '99',
                    'campaign_name' => 'Meta Conversion',
                    'spend' => '25.50',
                    'impressions' => '100',
                    'clicks' => '4',
                    'date_start' => '2026-08-01',
                ]],
            ], 200),
        ]);

        $rows = app(\App\Services\Marketing\AdSpend\MetaAdsSpendProvider::class)
            ->fetch(Carbon::parse('2026-08-01')->startOfDay(), Carbon::parse('2026-08-01')->endOfDay());

        $this->assertCount(1, $rows);
        $this->assertSame('meta', $rows[0]['platform']);
        $this->assertSame(25.5, $rows[0]['spend']);
        $this->assertSame('Meta Conversion', $rows[0]['campaign_name']);
    }

    private function actingEmployeeWithAnalytics(): Employee
    {
        $role = Role::query()->create([
            'name' => 'analyst',
            'title_ar' => 'محلل',
            'is_active' => true,
        ]);
        $view = Permission::query()->create([
            'name' => 'analytics.view',
            'section' => 'analytics',
            'action' => 'view',
            'action_label_ar' => 'عرض',
            'is_active' => true,
        ]);
        $edit = Permission::query()->create([
            'name' => 'analytics.edit',
            'section' => 'analytics',
            'action' => 'edit',
            'action_label_ar' => 'تعديل',
            'is_active' => true,
        ]);
        $create = Permission::query()->create([
            'name' => 'analytics.create',
            'section' => 'analytics',
            'action' => 'create',
            'action_label_ar' => 'إضافة',
            'is_active' => true,
        ]);
        DB::table('role_permissions')->insert([
            ['role_id' => $role->id, 'permission_id' => $view->id, 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $role->id, 'permission_id' => $edit->id, 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $role->id, 'permission_id' => $create->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $employee = Employee::query()->create([
            'name' => 'Admin Analyst',
            'email' => 'analyst@aqdi.test',
            'password' => Hash::make('password'),
            'is_active' => true,
            'role_id' => $role->id,
        ]);

        Sanctum::actingAs($employee);

        return $employee;
    }

    private function createMinimalSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('fname')->nullable();
            $table->string('lname')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->string('password')->nullable();
            $table->string('verification_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('utm_source', 64)->nullable();
            $table->string('utm_medium', 64)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('utm_term', 191)->nullable();
            $table->string('utm_content', 191)->nullable();
            $table->string('gclid', 191)->nullable();
            $table->string('fbclid', 191)->nullable();
            $table->string('ttclid', 191)->nullable();
            $table->string('twclid', 191)->nullable();
            $table->string('sccid', 191)->nullable();
            $table->timestamp('attributed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('contract_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        DB::table('contract_statuses')->insert(['id' => 1, 'name' => 'جديد']);

        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('uuid')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->string('contract_type')->nullable();
            $table->string('app_or_web')->nullable();
            $table->unsignedBigInteger('contract_status_id')->nullable();
            $table->string('status')->nullable();
            $table->integer('step')->default(1);
            $table->boolean('is_completed')->default(false);
            $table->boolean('is_draft')->default(false);
            $table->boolean('is_delete')->default(false);
            $table->string('utm_source', 64)->nullable();
            $table->string('utm_medium', 64)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('utm_term', 191)->nullable();
            $table->string('utm_content', 191)->nullable();
            $table->string('gclid', 191)->nullable();
            $table->string('fbclid', 191)->nullable();
            $table->string('ttclid', 191)->nullable();
            $table->string('twclid', 191)->nullable();
            $table->string('sccid', 191)->nullable();
            $table->timestamp('attributed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('contract_uuid')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('ad_spend_dailies', function (Blueprint $table): void {
            $table->id();
            $table->date('spent_on');
            $table->string('platform', 32);
            $table->string('campaign_id', 64)->default('');
            $table->string('campaign_name', 191)->nullable();
            $table->string('keyword', 191)->default('');
            $table->decimal('spend', 12, 2)->default(0);
            $table->char('currency', 3)->default('SAR');
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedInteger('clicks')->nullable();
            $table->string('ingest_source', 16)->default('api');
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('section')->nullable();
            $table->string('action')->nullable();
            $table->string('action_label_ar')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('role_id')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
}
