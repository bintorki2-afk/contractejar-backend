<?php

namespace Tests\Feature\Admin;

use App\Http\Resources\Admin\V2\Api\RoleDetailResource;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Admin\RolePermissionResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeRefreshTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.url' => 'http://localhost',
            'admin_auth.access_token_ttl_seconds' => 12 * 60 * 60,
            'admin_auth.refresh_token_ttl_seconds' => 8 * 60 * 60,
            'admin_auth.remembered_refresh_token_ttl_seconds' => 30 * 24 * 60 * 60,
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        URL::forceRootUrl('http://localhost');

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach (['role_permissions', 'permissions', 'employee_refresh_tokens', 'personal_access_tokens', 'employees', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_login_issues_twelve_hour_access_and_remembered_refresh_tokens(): void
    {
        $this->createEmployee();

        $response = $this->postJson(route('employees.login', absolute: false), [
            'email' => 'employee@example.com',
            'password' => 'password',
            'remember_me' => true,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_expires_in', 12 * 60 * 60);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotEmpty($response->json('data.refresh_token'));
        $this->assertSame('morning', $response->json('data.work_period'));
        $this->assertSame('صباحا', $response->json('data.work_period_label_ar'));

        $accessToken = DB::table('personal_access_tokens')->first();
        $refreshToken = DB::table('employee_refresh_tokens')->first();

        $this->assertNotNull($accessToken->expires_at);
        $this->assertEqualsWithDelta(
            now()->addHours(12)->timestamp,
            \Carbon\Carbon::parse($accessToken->expires_at)->timestamp,
            2
        );
        $this->assertSame(1, (int) $refreshToken->remembered);
        $this->assertEqualsWithDelta(
            now()->addDays(30)->timestamp,
            \Carbon\Carbon::parse($refreshToken->expires_at)->timestamp,
            2
        );
    }

    public function test_login_returns_the_selected_evening_work_period(): void
    {
        $this->createEmployee(workPeriod: 'evening');

        $this->postJson(route('employees.login', absolute: false), [
            'email' => 'employee@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.work_period', 'evening')
            ->assertJsonPath('data.work_period_label_ar', 'مساء')
            ->assertJsonPath('data.shift.start', '17:00')
            ->assertJsonPath('data.shift.end', '01:00');
    }

    public function test_refresh_rotates_token_and_rejects_reuse_of_the_old_token(): void
    {
        $this->createEmployee();
        $login = $this->postJson(route('employees.login', absolute: false), [
            'email' => 'employee@example.com',
            'password' => 'password',
        ])->assertOk();
        $oldRefreshToken = $login->json('data.refresh_token');

        $refreshed = $this->postJson(route('employees.refresh-token', absolute: false), [
            'refresh_token' => $oldRefreshToken,
        ])->assertOk()
            ->assertJsonPath('data.token_expires_in', 12 * 60 * 60);

        $this->assertNotSame($oldRefreshToken, $refreshed->json('data.refresh_token'));
        $this->assertNotEmpty($refreshed->json('data.token'));
        $this->assertDatabaseHas('employee_refresh_tokens', [
            'token_hash' => hash('sha256', $oldRefreshToken),
        ]);
        $this->assertNotNull(
            DB::table('employee_refresh_tokens')
                ->where('token_hash', hash('sha256', $oldRefreshToken))
                ->value('revoked_at')
        );

        $this->postJson(route('employees.refresh-token', absolute: false), [
            'refresh_token' => $oldRefreshToken,
        ])->assertUnauthorized();
    }

    public function test_login_and_refresh_return_the_same_effective_role_permissions(): void
    {
        $role = $this->createRole('operator');
        $permission = $this->createPermission('all_requests', 'view');
        $role->permissions()->attach($permission);
        $this->createEmployee($role);

        $login = $this->postJson(route('employees.login', absolute: false), [
            'email' => 'employee@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.role_id', $role->id)
            ->assertJsonPath('data.role', 'operator')
            ->assertJsonPath('data.is_system_admin', false)
            ->assertJsonPath('data.has_all_permissions', false)
            ->assertJsonPath('data.permissions.0', 'all_requests.view')
            ->assertJsonPath('data.permission_names.0', 'all_requests.view')
            ->assertJsonPath('data.permission_matrix.all_requests.0', 'view');

        $refresh = $this->postJson(route('employees.refresh-token', absolute: false), [
            'refresh_token' => $login->json('data.refresh_token'),
        ])->assertOk()
            ->assertJsonPath('data.role_id', $role->id)
            ->assertJsonPath('data.role', 'operator')
            ->assertJsonPath('data.is_system_admin', false);

        $this->assertSame($login->json('data.permissions'), $refresh->json('data.permissions'));
        $this->assertSame($login->json('data.permission_names'), $refresh->json('data.permission_names'));
        $this->assertSame($login->json('data.permission_matrix'), $refresh->json('data.permission_matrix'));
    }

    public function test_full_access_admin_receives_the_complete_configured_matrix(): void
    {
        $role = $this->createRole('admin');
        $this->createEmployee($role);

        $response = $this->postJson(route('employees.login', absolute: false), [
            'email' => 'employee@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.is_system_admin', true)
            ->assertJsonPath('data.has_all_permissions', true);

        // دفعة (د): بعض الإجراءات خاصة بأقسام (payments.refund) ⇒ العدد من الكتالوج نفسه.
        $expectedCount = count(app(\App\Modules\Employees\Services\RolePermissionResolver::class)->configuredPermissionNames());

        $this->assertCount($expectedCount, $response->json('data.permission_names'));
        $common = ['view', 'create', 'edit', 'delete', 'retrieve'];
        $this->assertSame($common, $response->json('data.permission_matrix.analytics'));
        $this->assertSame($common, $response->json('data.permission_matrix.seo_crawl'));
        // دفعة (د) — ب8: payments.refund خاص بقسم المدفوعات.
        $this->assertSame([...$common, 'refund'], $response->json('data.permission_matrix.payments'));
        $this->assertNotEmpty($response->json('data.permission_modules'));
        $this->assertTrue(collect($response->json('data.permission_modules'))
            ->firstWhere('section_key', 'analytics')['actions'][0]['granted']);
    }

    public function test_profile_returns_the_authenticated_employee_permissions_without_new_tokens(): void
    {
        $role = $this->createRole('admin');
        $employee = $this->createEmployee($role);

        $login = $this->postJson(route('employees.login', absolute: false), [
            'email' => 'employee@example.com',
            'password' => 'password',
        ])->assertOk();

        $profile = $this->withToken($login->json('data.token'))
            ->getJson(route('employees.me', absolute: false))
            ->assertOk()
            ->assertJsonPath('data.id', $employee->id)
            ->assertJsonPath('data.is_system_admin', true);

        $this->assertArrayNotHasKey('token', $profile->json('data'));

        $this->assertSame($login->json('data.permissions'), $profile->json('data.permissions'));
        $this->assertSame($login->json('data.permission_matrix'), $profile->json('data.permission_matrix'));
        $this->assertNotEmpty($profile->json('data.permission_modules'));

        $this->withToken($login->json('data.token'))
            ->getJson(route('employees.profile', absolute: false))
            ->assertOk()
            ->assertJsonPath('data.id', $employee->id);
    }

    public function test_role_detail_keeps_permission_objects_and_ids_and_adds_effective_contracts(): void
    {
        $role = $this->createRole('operator');
        $permission = $this->createPermission('employees', 'edit');
        $role->permissions()->attach($permission);
        $role->load('permissions');

        $payload = (new RoleDetailResource($role))->resolve(app(\Illuminate\Http\Request::class));

        $this->assertSame([$permission->id], $payload['permission_ids']);
        $this->assertSame('employees.edit', $payload['permissions'][0]['name']);
        $this->assertSame(['employees.edit'], $payload['permission_names']);
        $this->assertSame(['edit'], $payload['permission_matrix']['employees']);
        $this->assertFalse($payload['is_full_access']);
    }

    public function test_catalog_sync_is_idempotent_and_preserves_existing_admin_grants(): void
    {
        $role = $this->createRole('admin');
        $legacyPermission = Permission::query()->create([
            'name' => 'legacy_module.view',
            'section' => 'legacy_module',
            'section_en' => 'Legacy Module',
            'action' => 'view',
            'action_label_ar' => 'عرض',
            'action_label_en' => 'View',
            'is_active' => true,
        ]);
        $role->permissions()->attach($legacyPermission);
        $resolver = app(RolePermissionResolver::class);
        $configuredCount = count($resolver->configuredPermissionNames());

        $this->assertSame($configuredCount, $resolver->grantAllPermissionsToFullAccessRoles());
        $this->assertSame(0, $resolver->grantAllPermissionsToFullAccessRoles());
        $this->assertSame($configuredCount + 1, $role->permissions()->count());
        $this->assertTrue($role->permissions()->whereKey($legacyPermission->id)->exists());
    }

    public function test_logout_revokes_the_submitted_refresh_token(): void
    {
        $this->createEmployee();
        $login = $this->postJson(route('employees.login', absolute: false), [
            'email' => 'employee@example.com',
            'password' => 'password',
        ])->assertOk();

        $this->withToken($login->json('data.token'))
            ->postJson(route('employees.logout', absolute: false), [
                'refresh_token' => $login->json('data.refresh_token'),
            ])
            ->assertOk();

        $this->postJson(route('employees.refresh-token', absolute: false), [
            'refresh_token' => $login->json('data.refresh_token'),
        ])->assertUnauthorized();
    }

    public function test_permission_denial_is_a_stable_403_while_missing_credentials_is_401(): void
    {
        $this->getJson(route('dashboard-analytics', absolute: false))->assertUnauthorized();

        $employee = $this->createEmployee();

        Sanctum::actingAs($employee);

        $this->getJson(route('reports.orders', absolute: false))
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'code' => 'forbidden',
            ]);

        $this->getJson(route('dashboard-analytics', absolute: false))
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'code' => 'forbidden',
            ]);
    }

    public function test_admin_display_title_without_admin_slug_is_system_admin(): void
    {
        $role = Role::query()->create([
            'name' => 'branch_owner',
            'title_ar' => 'أدمن',
            'title_en' => 'Admin',
            'is_active' => true,
        ]);
        $this->createEmployee($role);

        $response = $this->postJson(route('employees.login', absolute: false), [
            'email' => 'employee@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.is_system_admin', true)
            ->assertJsonPath('data.role', 'branch_owner');

        $this->assertNotEmpty($response->json('data.permissions'));
        $this->assertNotEmpty($response->json('data.permission_matrix.analytics'));
        $this->assertTrue($response->json('data.has_all_permissions'));
        $this->assertTrue($response->json('data.is_system_admin'));

        $gated = $this->withToken($response->json('data.token'))
            ->getJson(route('dashboard-analytics', absolute: false));

        $this->assertNotEquals(401, $gated->status(), $gated->getContent());
        $this->assertNotEquals(403, $gated->status(), $gated->getContent());
    }

    public function test_admin_role_without_attached_permissions_is_not_forbidden(): void
    {
        $role = $this->createRole('admin');
        $this->createEmployee($role);

        $login = $this->postJson(route('employees.login', absolute: false), [
            'email' => 'employee@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.is_system_admin', true);

        $gated = $this->withToken($login->json('data.token'))
            ->getJson(route('dashboard-analytics', absolute: false));

        $this->assertNotEquals(401, $gated->status(), $gated->getContent());
        $this->assertNotEquals(403, $gated->status(), $gated->getContent());
    }

    public function test_non_admin_employee_is_limited_to_assigned_permissions_only(): void
    {
        $role = $this->createRole('operator');
        $this->createEmployee($role);

        $login = $this->postJson(route('employees.login', absolute: false), [
            'email' => 'employee@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.is_system_admin', false)
            ->assertJsonPath('data.has_all_permissions', false);

        $this->assertSame([], $login->json('data.permissions'));
        $this->assertSame([], $login->json('data.permission_matrix'));

        $this->withToken($login->json('data.token'))
            ->getJson(route('dashboard-analytics', absolute: false))
            ->assertForbidden();
    }

    public function test_legacy_admin_role_column_grants_full_access_even_without_role_permissions(): void
    {
        $limited = $this->createRole('operator');
        Employee::query()->create([
            'role_id' => $limited->id,
            'role' => 'admin',
            'work_period' => 'morning',
            'name' => 'Legacy Admin',
            'email' => 'legacy-admin@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $this->postJson(route('employees.login', absolute: false), [
            'email' => 'legacy-admin@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.is_system_admin', true)
            ->assertJsonPath('data.has_all_permissions', true);
    }

    private function createEmployee(?Role $role = null, string $workPeriod = 'morning'): Employee
    {
        return Employee::query()->create([
            'role_id' => $role?->id,
            'role' => $role?->name,
            'work_period' => $workPeriod,
            'name' => 'Test Employee',
            'email' => 'employee@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    private function createRole(string $name): Role
    {
        return Role::query()->create([
            'name' => $name,
            'title_ar' => $name,
            'title_en' => $name,
            'is_active' => true,
        ]);
    }

    private function createPermission(string $section, string $action): Permission
    {
        return Permission::query()->create([
            'name' => "{$section}.{$action}",
            'section' => $section,
            'section_en' => $section,
            'action' => $action,
            'action_label_ar' => $action,
            'action_label_en' => $action,
            'is_active' => true,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->nullable();
            $table->string('name');
            $table->decimal('base_salary', 10, 2)->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->unique();
            $table->string('profile_image')->nullable();
            $table->string('facebook')->nullable();
            $table->string('instagram')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('snapchat')->nullable();
            $table->string('tiktok')->nullable();
            $table->string('twitter')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_online')->default(false);
            $table->text('reason_of_block')->nullable();
            $table->timestamp('blocked_until')->nullable();
            $table->string('password');
            $table->string('role')->nullable();
            $table->string('work_period', 16)->default('morning');
            $table->string('fcm_token')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id');
            $table->string('token_hash', 64)->unique();
            $table->boolean('remembered')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('section');
            $table->string('section_en')->nullable();
            $table->string('action');
            $table->string('action_label_ar');
            $table->string('action_label_en')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id');
            $table->foreignId('permission_id');
            $table->unique(['role_id', 'permission_id']);
            $table->timestamps();
        });
    }
}
