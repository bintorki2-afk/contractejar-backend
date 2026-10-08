<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Services\Admin\RolePermissionResolver;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $resolver = app(RolePermissionResolver::class);
        $created = $resolver->syncAllPermissionsFromConfig();
        $granted = $resolver->grantAllPermissionsToFullAccessRoles();

        $assigned = $this->grantDefaultRolePermissions();

        $this->command?->info(
            "Permissions synced. Created: {$created}; full-access grants added: {$granted}; "
            ."default role grants added: {$assigned}"
        );
    }

    /**
     * Give the non-admin seeded roles a sensible starting set of permissions so
     * they are not created completely empty (which left them unusable until an
     * admin hand-picked every permission). Add-only (syncWithoutDetaching), so
     * any permissions an admin later grants through the dashboard are preserved.
     *
     * @return int number of role-permission rows newly attached
     */
    protected function grantDefaultRolePermissions(): int
    {
        // Action bundles.
        $FULL = ['view', 'create', 'edit', 'delete', 'retrieve']; // manage + remove
        $MANAGE = ['view', 'create', 'edit', 'retrieve'];         // manage, no delete
        $WORK = ['view', 'edit', 'retrieve'];                     // handle existing records
        $READ = ['view', 'retrieve'];                             // look only
        $VIEW = ['view'];

        $requestSections = [
            'lessor_change',
            'all_requests', 'completed_request', 'incomplete_request',
            'completed_whatsapp_request', 'incomplete_whatsapp_request',
            'returned_request',
        ];

        $defaults = [
            // المسؤول — broad operational manager (everything except roles,
            // permissions and system-level settings, which stay with the admin).
            'manager' => array_merge(
                $this->fill($requestSections, $FULL),
                $this->fill(['request_classification'], $FULL),
                $this->fill(['analytics'], $READ),
                $this->fill(['employees'], $MANAGE),
                $this->fill(['employee_salaries'], $READ),
                $this->fill(['employee_kpis'], $WORK),
                $this->fill(['users'], $WORK),
                $this->fill(['notifications'], $FULL),
                $this->fill(['payments'], $READ),
                $this->fill(['contract_payments'], $WORK),
                $this->fill(['real_estates'], $FULL),
                $this->fill(['property_reference', 'tenant_roles'], $MANAGE),
                $this->fill([
                    'contract_statuses', 'draft_contract_statuses',
                    'contract_periods', 'instrument_settings',
                    'regions', 'cities',
                ], $MANAGE),
                $this->fill(['contract_whatsapp'], $WORK),
                $this->fill([
                    'coupons', 'blogs', 'ads', 'faqs', 'paperworks',
                    'popup_contracts', 'instruction_sections', 'message_alerts',
                    'app_content', 'payment_messages',
                ], $FULL),
                $this->fill(['settings'], $VIEW),
            ),

            // موظف خدمة عملاء — intake and follow-up of contracts and clients.
            'customer_service' => array_merge(
                $this->fill($requestSections, $WORK),
                $this->fill(['request_classification'], $VIEW),
                $this->fill(['users'], $WORK),
                $this->fill(['notifications'], ['view', 'create']),
                $this->fill(['contract_whatsapp'], $WORK),
                $this->fill(['contract_statuses', 'draft_contract_statuses'], $VIEW),
                $this->fill(['payments'], $VIEW),
                $this->fill(['analytics'], $VIEW),
            ),

            // موظف استلام — receiving and handing over contracts.
            'receiver' => array_merge(
                $this->fill(['all_requests', 'completed_request', 'incomplete_request'], $WORK),
                $this->fill(['returned_request', 'lessor_change'], $WORK),
                $this->fill(['contract_statuses', 'draft_contract_statuses'], $VIEW),
                $this->fill(['users'], $VIEW),
                $this->fill(['contract_payments'], $WORK),
                $this->fill(['paperworks'], $WORK),
            ),

            // مشرف — reviewing and approving contracts.
            'supervisor' => array_merge(
                $this->fill($requestSections, $WORK),
                $this->fill(['request_classification'], $MANAGE),
                $this->fill(['contract_statuses', 'draft_contract_statuses'], $MANAGE),
                $this->fill(['analytics'], $READ),
                $this->fill(['employee_kpis'], $VIEW),
                $this->fill(['users'], $VIEW),
                $this->fill(['payments'], $VIEW),
            ),
        ];

        $attached = 0;

        foreach ($defaults as $roleName => $permissionNames) {
            $role = Role::query()->where('name', $roleName)->first();
            if (! $role || $role->isFullAccess()) {
                continue;
            }

            $ids = Permission::query()
                ->whereIn('name', $permissionNames)
                ->pluck('id')
                ->all();

            if (empty($ids)) {
                continue;
            }

            $changes = $role->permissions()->syncWithoutDetaching($ids);
            $attached += count($changes['attached']);
        }

        return $attached;
    }

    /**
     * Expand a list of section keys × actions into "section.action" names.
     *
     * @param  array<int, string>  $sections
     * @param  array<int, string>  $actions
     * @return array<int, string>
     */
    protected function fill(array $sections, array $actions): array
    {
        $names = [];
        foreach ($sections as $section) {
            foreach ($actions as $action) {
                $names[] = "{$section}.{$action}";
            }
        }

        return $names;
    }
}
