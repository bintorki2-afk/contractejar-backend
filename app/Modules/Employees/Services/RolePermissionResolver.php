<?php

namespace App\Modules\Employees\Services;

use App\Models\Employee;
use App\Models\Permission;
use App\Modules\Employees\Models\Role;
use Illuminate\Support\Collection;

class RolePermissionResolver
{
    /**
     * @param  array<int>|null  $permissionIds
     * @param  array<string, array<int, string>>|null  $permissionMatrix  section_key => [actions]
     * @return array<int>
     */
    public function resolve(
        ?array $permissionIds,
        ?array $permissionMatrix,
        bool $activateAll = false
    ): array {
        if ($activateAll) {
            $this->syncAllPermissionsFromConfig();

            return Permission::query()
                ->whereIn('name', $this->configuredPermissionNames())
                ->pluck('id')
                ->all();
        }

        $ids = collect($permissionIds ?? []);

        if (! empty($permissionMatrix)) {
            $ids = $ids->merge($this->idsFromMatrix($permissionMatrix));
        }

        return $ids->unique()->filter()->values()->all();
    }

    /**
     * @param  array<string, array<int, string>>  $matrix
     * @return Collection<int, int>
     */
    protected function idsFromMatrix(array $matrix): Collection
    {
        $ids = collect();
        $configuredSections = array_keys(config('permissions.sections', []));
        $configuredActions = array_keys(config('permissions.actions', []));

        foreach ($matrix as $section => $actions) {
            $sectionKey = $this->normalizeSectionKey((string) $section);
            if (! in_array($sectionKey, $configuredSections, true)) {
                continue;
            }

            foreach ((array) $actions as $action) {
                $action = strtolower(trim((string) $action));
                if (! in_array($action, $configuredActions, true) || ! self::actionAllowedForSection($sectionKey, $action)) {
                    continue;
                }

                $permission = $this->findOrCreatePermission($sectionKey, $action);

                if ($permission) {
                    $ids->push($permission->id);
                }
            }
        }

        return $ids;
    }

    /** دفعة (د): بعض الإجراءات خاصة بأقسام محددة (مثل payments.refund). */
    public static function actionAllowedForSection(string $section, string $action): bool
    {
        $only = config('permissions.section_only_actions', []);
        if (! array_key_exists($action, $only)) {
            return true;
        }

        return in_array($section, (array) $only[$action], true);
    }

    public function normalizeSectionKey(string $section): string
    {
        $section = trim(strtolower($section));
        $section = preg_replace('/\s+/', '_', $section);
        $section = preg_replace('/[^a-z0-9_]/', '', $section);

        return $section !== '' ? $section : 'section';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function groupedPermissionsForForm(): array
    {
        return $this->allModulesForForm();
    }

    /**
     * Full permission grid: every section × every action (for add/edit role UI).
     *
     * @return array<int, array<string, mixed>>
     */
    public function allModulesForForm(): array
    {
        return $this->modulesFromNames(null);
    }

    /**
     * Full permission grid with granted flags for an employee.
     *
     * @return array<int, array<string, mixed>>
     */
    public function modulesForEmployee(Employee $employee): array
    {
        return $this->modulesFromNames($this->effectivePermissionsFor($employee)['names']);
    }

    /**
     * @param  array<int, string>|null  $grantedNames
     * @return array<int, array<string, mixed>>
     */
    protected function modulesFromNames(?array $grantedNames): array
    {
        $granted = $grantedNames === null ? null : array_fill_keys($grantedNames, true);
        $sections = config('permissions.sections', []);
        $actions = config('permissions.actions', []);
        $existing = Permission::query()
            ->where('is_active', true)
            ->get()
            ->groupBy('section');

        $modules = [];

        foreach ($sections as $sectionKey => $sectionLabels) {
            $sectionPermissions = $existing->get($sectionKey, collect());
            $actionRows = [];

            foreach ($actions as $actionKey => $actionLabels) {
                if (! self::actionAllowedForSection((string) $sectionKey, (string) $actionKey)) {
                    continue;
                }
                $permission = $sectionPermissions->firstWhere('action', $actionKey);
                $name = $permission?->name ?? "{$sectionKey}.{$actionKey}";
                $row = [
                    'action' => $actionKey,
                    'action_label_ar' => $actionLabels['ar'] ?? $actionKey,
                    'action_label_en' => $actionLabels['en'] ?? $actionKey,
                    'permission_id' => $permission?->id,
                    'permission_name' => $name,
                ];

                if ($granted !== null) {
                    $row['granted'] = isset($granted[$name]);
                }

                $actionRows[] = $row;
            }

            $modules[] = [
                'section_key' => $sectionKey,
                'section_label_ar' => $sectionLabels['ar'] ?? $sectionKey,
                'section_label_en' => $sectionLabels['en'] ?? $sectionKey,
                'actions' => $actionRows,
            ];
        }

        return $modules;
    }

    /**
     * Empty permission_matrix keyed by every section (for POST body shape).
     *
     * @return array<string, array<int, string>>
     */
    public function permissionMatrixTemplate(): array
    {
        $template = [];

        foreach (array_keys(config('permissions.sections', [])) as $sectionKey) {
            $template[$sectionKey] = [];
        }

        return $template;
    }

    public function findOrCreatePermission(string $sectionKey, string $action): ?Permission
    {
        $sectionKey = $this->normalizeSectionKey($sectionKey);
        $action = strtolower(trim($action));

        if (! array_key_exists($sectionKey, config('permissions.sections', []))
            || ! array_key_exists($action, config('permissions.actions', []))
            || ! self::actionAllowedForSection($sectionKey, $action)) {
            return null;
        }

        $name = "{$sectionKey}.{$action}";
        $sectionLabels = config("permissions.sections.{$sectionKey}", []);
        $actionLabels = config("permissions.actions.{$action}", []);

        return Permission::query()->firstOrCreate(
            ['name' => $name],
            [
                'section' => $sectionKey,
                'section_en' => $sectionLabels['en'] ?? $sectionKey,
                'action' => $action,
                'action_label_ar' => $actionLabels['ar'] ?? $action,
                'action_label_en' => $actionLabels['en'] ?? $action,
                'is_active' => true,
            ]
        );
    }

    /**
     * Ensure every section × action exists in DB (run once via seeder or create form).
     *
     * @return int Number of permissions created
     */
    public function syncAllPermissionsFromConfig(): int
    {
        $created = 0;

        foreach (array_keys(config('permissions.sections', [])) as $sectionKey) {
            foreach (array_keys(config('permissions.actions', [])) as $actionKey) {
                if (! self::actionAllowedForSection($sectionKey, $actionKey)) {
                    continue;
                }
                $permission = Permission::query()
                    ->where('name', "{$sectionKey}.{$actionKey}")
                    ->first();

                if (! $permission) {
                    $this->findOrCreatePermission($sectionKey, $actionKey);
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * Effective configured permissions for an authenticated employee.
     *
     * Admin employees receive every catalog permission. Regular employees
     * receive only the permissions attached to their role (possibly none).
     *
     * @return array{names: array<int, string>, matrix: array<string, array<int, string>>}
     */
    public function effectivePermissionsFor(Employee $employee): array
    {
        if ($employee->isSystemAdmin()) {
            return $this->permissionSetFromNames($this->configuredPermissionNames());
        }

        $employee->loadMissing('roleRelation.permissions');

        if (! $employee->roleRelation) {
            return $this->permissionSetFromNames([]);
        }

        return $this->effectivePermissionsForRole($employee->roleRelation);
    }

    /**
     * Effective configured permissions for a role.
     *
     * @return array{names: array<int, string>, matrix: array<string, array<int, string>>}
     */
    public function effectivePermissionsForRole(Role $role): array
    {
        if ($role->isFullAccess()) {
            return $this->permissionSetFromNames($this->configuredPermissionNames());
        }

        $role->loadMissing('permissions');

        $grantedNames = $role->permissions
            ->where('is_active', true)
            ->pluck('name')
            ->all();

        return $this->permissionSetFromNames($grantedNames);
    }

    /**
     * @return array<int, string>
     */
    public function configuredPermissionNames(): array
    {
        $names = [];

        foreach (array_keys(config('permissions.sections', [])) as $section) {
            foreach (array_keys(config('permissions.actions', [])) as $action) {
                if (! self::actionAllowedForSection($section, $action)) {
                    continue;
                }
                $names[] = "{$section}.{$action}";
            }
        }

        return $names;
    }

    /**
     * Grant every configured permission to full-access roles without detaching
     * any existing grants.
     *
     * @return int Number of newly attached role-permission rows
     */
    public function grantAllPermissionsToFullAccessRoles(): int
    {
        $this->syncAllPermissionsFromConfig();

        $permissionIds = Permission::query()
            ->whereIn('name', $this->configuredPermissionNames())
            ->pluck('id')
            ->all();
        $attached = 0;

        Role::query()->get()->each(function (Role $role) use ($permissionIds, &$attached) {
            if (! $role->isFullAccess()) {
                return;
            }

            $changes = $role->permissions()->syncWithoutDetaching($permissionIds);
            $attached += count($changes['attached']);
        });

        return $attached;
    }

    /**
     * @param  array<int, string>  $names
     * @return array{names: array<int, string>, matrix: array<string, array<int, string>>}
     */
    protected function permissionSetFromNames(array $names): array
    {
        $granted = array_fill_keys($names, true);
        $orderedNames = [];
        $matrix = [];

        foreach (array_keys(config('permissions.sections', [])) as $section) {
            foreach (array_keys(config('permissions.actions', [])) as $action) {
                $name = "{$section}.{$action}";

                if (! isset($granted[$name])) {
                    continue;
                }

                $orderedNames[] = $name;
                $matrix[$section] ??= [];
                $matrix[$section][] = $action;
            }
        }

        return [
            'names' => $orderedNames,
            'matrix' => $matrix,
        ];
    }
}
