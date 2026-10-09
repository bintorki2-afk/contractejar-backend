<?php

namespace Tests\Feature\Admin;

use App\Models\AdSpendDaily;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketingTrackingTest extends TestCase
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
        $this->actingEmployeeWithAnalytics();
        $this->seedTrackingData();
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

    public function test_channels_endpoint_returns_funnel_and_paid_channel_rows(): void
    {
        $body = $this->getJson('/api/admin/marketing-tracking/channels?period=last_30_days')
            ->assertOk()
            ->json('data');

        $this->assertSame('impressions', $body['funnel'][0]['key']);
        $this->assertSame(1000, $body['funnel'][0]['value']);
        $this->assertSame('clicks', $body['funnel'][1]['key']);
        $this->assertSame(80, $body['funnel'][1]['value']);
        $this->assertSame(2, $body['funnel'][2]['value']);
        $this->assertSame(1, $body['funnel'][3]['value']);

        $google = collect($body['channels'])->firstWhere('source', 'google');
        $this->assertSame(100, $google['spend']);
        $this->assertSame(200, $google['revenue']);
        $this->assertEquals(2, $google['roas']);
        $this->assertSame('good', $google['roas_tone']);
        $this->assertSame(1, $google['conversions']);
        $this->assertSame(2, $google['leads']);
        $this->assertSame(100, $google['cac']);
        $this->assertSame(100, $google['profit']);
        $this->assertSame('قوقل', $google['label_ar']);
    }

    public function test_keywords_endpoint_merges_utm_revenue_for_the_table(): void
    {
        $body = $this->getJson('/api/admin/marketing-tracking/keywords?period=last_30_days')
            ->assertOk()
            ->json('data');

        $this->assertSame(200, $body['summary']['organic_revenue']);
        $this->assertSame(1, $body['summary']['target_keywords']);
        $this->assertSame('عقد إيجار إلكتروني', $body['items'][0]['keyword']);
        $this->assertSame(200, $body['items'][0]['revenue']);
        $this->assertSame('stable', $body['items'][0]['status']);
        $this->assertArrayHasKey('competition', $body['items'][0]);
        $this->assertArrayHasKey('status_label_ar', $body['items'][0]);
    }

    public function test_overview_endpoint_matches_roas_and_widget_shape(): void
    {
        $body = $this->getJson('/api/admin/marketing-tracking?period=last_30_days')
            ->assertOk()
            ->json('data');

        $this->assertEquals(2, $body['summary']['roas']);
        $this->assertSame(100, $body['summary']['spend']);
        $this->assertSame(200, $body['summary']['revenue']);
        $this->assertSame(100, $body['summary']['profit']);
        $this->assertSame(100, $body['kpis']['cac']);
        $this->assertSame(1, $body['kpis']['paying_customers']);
        $this->assertSame(2, $body['kpis']['marketing_orders']);
        $this->assertSame('google', $body['chart'][0]['source']);
        $this->assertSame('عقد إيجار إلكتروني', $body['top_keywords'][0]['keyword']);
        $this->assertSame('Google Search - High Intent', $body['top_campaigns'][0]['campaign']);
        $this->assertSame('best', $body['best_campaign']['kind']);
        $this->assertEquals(2, $body['best_campaign']['roas']);
        $this->assertArrayHasKey('app_visits', $body['kpis']);
        $this->assertArrayHasKey('website_visits', $body['kpis']);
    }

    public function test_reports_overview_and_channel_table_match_ui_shape(): void
    {
        $overview = $this->getJson('/api/admin/marketing/reports?period=last_30_days')
            ->assertOk()
            ->json('data');

        $this->assertSame('last_30_days', $overview['period']);
        $this->assertSame('ريال', $overview['currency_label_ar']);
        $this->assertSame('best_page', $overview['highlights'][0]['key']);
        $this->assertSame('best_keyword', $overview['highlights'][1]['key']);
        $this->assertSame('orders', $overview['comparison']['items'][0]['key']);
        $this->assertSame(1, $overview['comparison']['items'][0]['value']);
        $this->assertTrue($overview['comparison']['items'][1]['is_money']);
        $this->assertSame('new_customers', $overview['stats'][0]['key']);
        $this->assertSame(100, $overview['stats'][2]['value']);
        $this->assertSame('x', $overview['stats'][4]['suffix']);

        $channels = $this->getJson('/api/admin/marketing/reports/channels?period=last_30_days')
            ->assertOk()
            ->json('data');

        $this->assertCount(5, $channels['rows']);
        $google = collect($channels['rows'])->firstWhere('source', 'google');
        $this->assertSame(2, $google['leads']);
        $this->assertSame(1, $google['conversions']);
        $this->assertSame(2, $channels['total']['leads']);
        $this->assertSame(1, $channels['total']['conversions']);
        $this->assertSame(100, $channels['total']['spend']);
        $this->assertSame(200, $channels['total']['revenue']);
        $this->assertStringContainsString('تقرير القنوات', $channels['range_label_ar']);
    }

    public function test_reports_export_csv_and_email(): void
    {
        Mail::fake();

        $csv = $this->postJson('/api/admin/marketing/reports/export', [
            'format' => 'csv',
            'period' => 'last_30_days',
        ]);
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
        $this->assertStringContainsString('google', $csv->streamedContent());

        $this->postJson('/api/admin/marketing/reports/export', [
            'format' => 'email',
            'period' => 'last_30_days',
        ])->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertSent(\App\Mail\MarketingReportMail::class);
    }

    private function seedTrackingData(): void
    {
        $attribution = [
            'utm_source' => 'google',
            'utm_campaign' => 'Google Search - High Intent',
            'utm_term' => 'عقد إيجار إلكتروني',
        ];

        $user = User::query()->create(array_merge([
            'fname' => 'خالد',
            'mobile' => '0500000099',
            'password' => Hash::make('password'),
            'platform' => User::PLATFORM_WEBSITE,
        ], $attribution));

        $paid = Contract::query()->create(array_merge([
            'user_id' => $user->id,
            'contract_type' => 'housing',
            'step' => 4,
            'is_delete' => 0,
            'is_completed' => 1,
        ], $attribution));
        $unpaid = Contract::query()->create(array_merge([
            'user_id' => $user->id,
            'contract_type' => 'housing',
            'step' => 4,
            'is_delete' => 0,
            'is_completed' => 0,
        ], $attribution));

        if (Schema::hasColumn('contracts', 'utm_source')) {
            DB::table('contracts')->whereIn('id', [$paid->id, $unpaid->id])->update($attribution);
        }

        Payment::query()->create([
            'contract_uuid' => (string) $paid->uuid,
            'amount' => 200,
            'status' => 'success',
        ]);

        AdSpendDaily::query()->create([
            'spent_on' => now()->toDateString(),
            'platform' => 'google',
            'campaign_id' => 'camp-1',
            'campaign_name' => 'Google Search - High Intent',
            'keyword' => '',
            'spend' => 100,
            'currency' => 'SAR',
            'impressions' => 1000,
            'clicks' => 80,
            'ingest_source' => 'manual',
        ]);
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
        DB::table('role_permissions')->insert([
            ['role_id' => $role->id, 'permission_id' => $view->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $employee = Employee::query()->create([
            'name' => 'Tracking Admin',
            'email' => 'tracking@aqdi.test',
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
            $table->string('mobile')->nullable();
            $table->string('password')->nullable();
            $table->string('verification_code')->nullable();
            $table->string('platform')->nullable();
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
