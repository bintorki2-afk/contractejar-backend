<?php

namespace Tests\Feature\Admin;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * فحص: منع تصعيد الصلاحيات والاستيلاء على حساب مدير النظام من لوحة الإدارة.
 *  - assignPermissions كان بلا حارس تصعيد (DASHBOARD-1).
 *  - تعديل/حظر/حذف موظف هدفه مدير نظام من غير مدير نظام (DASHBOARD-2).
 */
class EmployeeRolePrivilegeEscalationTest extends TestCase
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
        Artisan::call('db:seed', ['--class' => 'PermissionSeeder', '--force' => true]);
    }

    private function role(string $name, array $permissionNames = []): Role
    {
        $role = Role::query()->create([
            'name' => $name,
            'title_ar' => $name,
            'title_en' => $name,
            'is_active' => true,
        ]);

        if ($permissionNames !== []) {
            $ids = Permission::query()->whereIn('name', $permissionNames)->pluck('id')->all();
            $role->permissions()->sync($ids);
        }

        return $role;
    }

    private function employee(Role $role): Employee
    {
        return Employee::query()->create([
            'name' => 'موظف '.$role->name,
            'email' => $role->name.uniqid().'@test.local',
            'password' => Hash::make('secret'),
            'is_active' => true,
            'role_id' => $role->id,
            'role' => $role->name,
        ]);
    }

    public function test_non_admin_with_roles_edit_cannot_grant_permissions_it_lacks(): void
    {
        $editorRole = $this->role('roles_editor', ['roles.view', 'roles.edit']);
        $actor = $this->employee($editorRole);
        Sanctum::actingAs($actor);

        $targetRole = $this->role('target_role', ['roles.view']);

        // يحاول منح كل الصلاحيات (بما فيها ما لا يملكه) → 403.
        $allIds = Permission::query()->pluck('id')->all();
        $this->postJson('/api/admin/roles/'.$targetRole->id.'/assign-permissions', [
            'permission_ids' => $allIds,
            'activate_all_permissions' => true,
        ])->assertStatus(403);

        $targetRole->refresh()->load('permissions');
        $this->assertSame(1, $targetRole->permissions->count());
    }

    public function test_admin_can_assign_permissions(): void
    {
        $adminRole = $this->role('admin');
        Sanctum::actingAs($this->employee($adminRole));

        $targetRole = $this->role('svc', ['all_requests.view']);
        $ids = Permission::query()->whereIn('name', ['all_requests.view', 'all_requests.edit'])->pluck('id')->all();

        $this->postJson('/api/admin/roles/'.$targetRole->id.'/assign-permissions', [
            'permission_ids' => $ids,
        ])->assertOk();

        $this->assertSame(2, $targetRole->fresh()->load('permissions')->permissions->count());
    }

    public function test_non_admin_cannot_modify_a_system_admin_employee(): void
    {
        $managerRole = $this->role('manager_emp', ['employees.view', 'employees.edit']);
        Sanctum::actingAs($this->employee($managerRole));

        $adminRole = $this->role('admin');
        $adminTarget = $this->employee($adminRole);

        // تغيير كلمة مرور مدير النظام → 403.
        $this->postJson('/api/admin/employees/'.$adminTarget->id, [
            'password' => 'NewPass#123',
        ])->assertStatus(403);

        // حظر مدير النظام → 403.
        $this->postJson('/api/admin/employees/'.$adminTarget->id.'/block', [
            'blocked_until' => now()->addDay()->toDateTimeString(),
            'reason_of_block' => 'x',
        ])->assertStatus(403);

        // التأكد أن كلمة المرور لم تتغيّر.
        $this->assertTrue(Hash::check('secret', $adminTarget->fresh()->password));
    }

    public function test_paid_order_cannot_be_deleted_without_force(): void
    {
        $adminRole = $this->role('admin');
        $admin = $this->employee($adminRole);
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $user = \App\Modules\Users\Models\User::query()->create([
            'mobile' => '0551110099', 'email' => 'pd@test.local',
            'password' => bcrypt('x'), 'is_active' => true,
        ]);
        $contract = \App\Models\Contract::query()->create([
            'user_id' => $user->id, 'contract_type' => 'housing', 'is_completed' => 1,
        ]);
        DB::table('payments')->insert([
            'name' => 'p', 'contract_uuid' => $contract->uuid, 'amount' => 349, 'status' => 'success',
            'payment_method' => 'moyasar', 'tran_currency' => 'SAR',
            'payment_date' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // بلا force → 422، والطلب ودفعته باقيان.
        $this->postJson('/api/admin/orders/'.$contract->id.'/delete')->assertStatus(422);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
        $this->assertSame(1, DB::table('payments')->where('contract_uuid', $contract->uuid)->count());

        // مدير النظام مع force=1 → يُحذف.
        $this->postJson('/api/admin/orders/'.$contract->id.'/delete', ['force' => true])->assertOk();
        $this->assertDatabaseMissing('contracts', ['id' => $contract->id]);
    }

    public function test_admin_can_modify_another_system_admin_employee(): void
    {
        $adminRole = $this->role('admin');
        Sanctum::actingAs($this->employee($adminRole));

        $adminTarget = $this->employee($this->role('admin2_is_admin', []));
        // اجعل الهدف مدير نظام عبر اسم دور كامل الصلاحيات.
        $adminTarget->role_id = $adminRole->id;
        $adminTarget->role = 'admin';
        $adminTarget->save();

        $this->postJson('/api/admin/employees/'.$adminTarget->id, [
            'name' => 'اسم محدث',
        ])->assertOk();
    }
}
