<?php

namespace Tests\Feature\Admin;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketingTrackingMissingSchemaTest extends TestCase
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

        $this->createLiveLikeSchema();
        $this->actingEmployeeWithAnalytics();
        $this->seedFromUserAttribution();
    }

    protected function tearDown(): void
    {
        foreach ([
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

    public function test_tracking_endpoints_do_not_500_without_contract_utm_or_ad_spend_table(): void
    {
        $this->assertFalse(Schema::hasColumn('contracts', 'utm_source'));
        $this->assertFalse(Schema::hasTable('ad_spend_dailies'));
        $this->assertTrue(Schema::hasColumn('users', 'utm_source'));

        $overview = $this->getJson('/api/admin/marketing-tracking?period=last_30_days')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $overview['summary']['spend']);
        $this->assertSame(200, $overview['summary']['revenue']);
        $this->assertNull($overview['summary']['roas']);
        $this->assertSame(2, $overview['kpis']['marketing_orders']);
        $this->assertSame('عقد إيجار إلكتروني', $overview['top_keywords'][0]['keyword']);

        $keywords = $this->getJson('/api/admin/marketing-tracking/keywords?period=last_30_days')
            ->assertOk()
            ->json('data');
        $this->assertSame(200, $keywords['summary']['organic_revenue']);
        $this->assertSame('عقد إيجار إلكتروني', $keywords['items'][0]['keyword']);

        $channels = $this->getJson('/api/admin/marketing-tracking/channels?period=last_30_days')
            ->assertOk()
            ->json('data');
        $this->assertSame(0, $channels['funnel'][0]['value']);
        $this->assertSame(2, $channels['funnel'][2]['value']);
        $google = collect($channels['channels'])->firstWhere('source', 'google');
        $this->assertSame(0, $google['spend']);
        $this->assertSame(200, $google['revenue']);
        $this->assertNull($google['roas']);
    }

    private function seedFromUserAttribution(): void
    {
        $user = User::query()->create([
            'fname' => 'خالد',
            'mobile' => '0500000098',
            'password' => Hash::make('password'),
        ]);
        // utm_* are not mass-assignable; production sets them server-side via
        // AttributionService. contracts has no utm columns here (live-like), so
        // all attribution must come from the user's first touch.
        $user->forceFill([
            'utm_source' => 'google',
            'utm_campaign' => 'Google Search - High Intent',
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
    }

    private function actingEmployeeWithAnalytics(): void
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
            'email' => 'tracking-live@aqdi.test',
            'password' => Hash::make('password'),
            'is_active' => true,
            'role_id' => $role->id,
        ]);

        Sanctum::actingAs($employee);
    }

    private function createLiveLikeSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('fname')->nullable();
            $table->string('mobile')->nullable();
            $table->string('password')->nullable();
            $table->string('verification_code')->nullable();
            $table->string('utm_source', 64)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('utm_term', 191)->nullable();
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
            $table->boolean('is_delete')->default(false);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('contract_uuid')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('status')->nullable();
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
