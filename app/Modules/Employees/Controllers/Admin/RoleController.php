<?php

namespace App\Modules\Employees\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\StoreRoleRequest;
use App\Http\Requests\Admin\V2\UpdateRoleRequest;
use App\Http\Resources\Admin\V2\Api\RoleDetailResource;
use App\Http\Resources\Admin\V2\Api\RoleResource;
use App\Http\Traits\Responser;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Support\AuthenticatedEmployee;
use App\Services\Admin\RolePermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class RoleController extends Controller
{
    use Responser;

    public function __construct(
        protected RolePermissionResolver $permissionResolver
    ) {}

    public function index(Request $request)
    {
        try {
            $query = Role::query()
                ->with(['employees:id,name,email,role_id'])
                ->withCount('permissions');

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->filled('search')) {
                $search = $request->string('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('title_ar', 'like', "%{$search}%")
                        ->orWhere('title_en', 'like', "%{$search}%")
                        ->orWhereHas('employees', function ($eq) use ($search) {
                            $eq->where('name', 'like', "%{$search}%");
                        });
                });
            }

            $sortBy = $request->get('sort_by', 'updated_at');
            $sortOrder = strtolower((string) $request->get('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
            $allowedSort = ['created_at', 'updated_at', 'name', 'title_ar'];
            if (! in_array($sortBy, $allowedSort, true)) {
                $sortBy = 'updated_at';
            }
            $query->orderBy($sortBy, $sortOrder);

            $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
            $roles = $query->paginate($perPage);

            return $this->apiResponse([
                'items' => RoleResource::collection($roles),
                'pagination' => $this->paginate($roles),
            ], trans('api.success'));
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred') . ': ' . $e->getMessage(), 500);
        }
    }

    public function create()
    {
        try {
            $employees = Employee::query()
                ->select('id', 'name', 'email', 'role_id')
                ->orderBy('name')
                ->get()
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'email' => $e->email,
                    'role_id' => $e->role_id,
                ]);

            $actions = collect(config('permissions.actions', []))->map(
                fn ($labels, $value) => [
                    'value' => $value,
                    'label_ar' => $labels['ar'] ?? $value,
                    'label_en' => $labels['en'] ?? $value,
                ]
            )->values();

            $sections = collect(config('permissions.sections', []))->map(
                fn ($labels, $key) => [
                    'section_key' => $key,
                    'section_label_ar' => $labels['ar'] ?? $key,
                    'section_label_en' => $labels['en'] ?? $key,
                ]
            )->values();

            $this->permissionResolver->syncAllPermissionsFromConfig();
            $modules = $this->permissionResolver->allModulesForForm();

            return $this->apiResponse([
                'employees' => $employees,
                'permission_actions' => $actions,
                'permission_sections' => $sections,
                'permission_modules' => $modules,
                'permissions_grouped' => $modules,
                'permission_matrix_template' => $this->permissionResolver->permissionMatrixTemplate(),
                'permission_matrix_example' => [
                    'employees' => ['view', 'create'],
                    'roles' => ['view'],
                    'settings' => ['view'],
                ],
                'validation_rules' => (new StoreRoleRequest)->rules(),
            ], trans('api.success'));
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * Prevent privilege escalation through role management. A non-admin actor
     * may not create/rename a role to a full-access (admin) role, nor grant a
     * role permissions it does not itself hold, nor assign an employee to such
     * a role. Admins are unaffected. Returns an error response or null.
     */
    protected function denyRoleEscalation(?array $permissionIds, bool $assigning, ?Role $existingRole, array $attrs): ?\Illuminate\Http\JsonResponse
    {
        $actor = AuthenticatedEmployee::from(request());
        if ($actor !== null && $actor->isSystemAdmin()) {
            return null;
        }

        $effectiveIds = $permissionIds;
        if ($effectiveIds === null && $assigning && $existingRole !== null) {
            $effectiveIds = $existingRole->permissions()->pluck('permissions.id')->all();
        }

        $name = $attrs['name'] ?? $existingRole?->name;
        $titleEn = $attrs['title_en'] ?? $existingRole?->title_en;
        $titleAr = $attrs['title_ar'] ?? $existingRole?->title_ar;
        $touchesIdentity = array_key_exists('name', $attrs) || array_key_exists('title_en', $attrs) || array_key_exists('title_ar', $attrs);
        if (($permissionIds !== null || $assigning || $touchesIdentity) && Role::grantsFullAccess($name, $titleEn, $titleAr)) {
            return $this->errorMessage(trans('api.forbidden'), 403);
        }

        if ($effectiveIds !== null && $effectiveIds !== []) {
            $names = Permission::query()->whereIn('id', $effectiveIds)->where('is_active', true)->pluck('name');
            foreach ($names as $permissionName) {
                if ($actor === null || ! $actor->hasPermission($permissionName)) {
                    return $this->errorMessage(trans('api.forbidden'), 403);
                }
            }
        }

        return null;
    }

    public function store(StoreRoleRequest $request)
    {
        try {
            $validated = $request->validated();
            $permissionIds = $this->resolvePermissionIdsFromValidated($validated);
            $employeeId = $validated['employee_id'] ?? null;

            unset(
                $validated['permissions'],
                $validated['permission_ids'],
                $validated['permission_matrix'],
                $validated['activate_all_permissions'],
                $validated['employee_id']
            );

            if ($denied = $this->denyRoleEscalation($permissionIds, (bool) $employeeId, null, $validated)) {
                return $denied;
            }

            $role = Role::query()->create($validated);

            if ($permissionIds !== null) {
                $role->permissions()->sync($permissionIds);
            }

            if ($employeeId) {
                Employee::query()->whereKey($employeeId)->update(['role_id' => $role->id]);
            }

            $role->load(['permissions', 'employees'])->loadCount('permissions');

            return $this->apiResponse(
                new RoleDetailResource($role),
                trans('api.created_successfully'),
                201
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred') . ': ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $role = Role::query()
                ->with(['permissions', 'employees'])
                ->withCount('permissions')
                ->find($id);

            if (! $role) {
                return $this->errorMessage(trans('api.not_found'), 404);
            }

            return $this->apiResponse(
                new RoleDetailResource($role),
                trans('api.success')
            );
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred') . ': ' . $e->getMessage(), 500);
        }
    }

    public function update(UpdateRoleRequest $request, $id)
    {
        try {
            $role = Role::query()->find($id);

            if (! $role) {
                return $this->errorMessage(trans('api.not_found'), 404);
            }

            $validated = $request->validated();
            $permissionIds = $this->resolvePermissionIdsFromValidated($validated, $request->hasAny([
                'permissions',
                'permission_ids',
                'permission_matrix',
                'activate_all_permissions',
            ]));
            $employeeId = array_key_exists('employee_id', $validated)
                ? $validated['employee_id']
                : null;

            unset(
                $validated['permissions'],
                $validated['permission_ids'],
                $validated['permission_matrix'],
                $validated['activate_all_permissions'],
                $validated['employee_id']
            );

            if ($denied = $this->denyRoleEscalation($permissionIds, $employeeId !== null, $role, $validated)) {
                return $denied;
            }

            if ($validated !== []) {
                $role->update($validated);
            }

            if ($permissionIds !== null) {
                $role->permissions()->sync($permissionIds);
            }

            if ($employeeId !== null) {
                Employee::query()->whereKey($employeeId)->update(['role_id' => $role->id]);
            }

            $role->load(['permissions', 'employees'])->loadCount('permissions');

            return $this->apiResponse(
                new RoleDetailResource($role->fresh()),
                trans('api.updated_successfully')
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred') . ': ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $role = Role::query()->find($id);

            if (! $role) {
                return $this->errorMessage(trans('api.not_found'), 404);
            }

            if ($role->employees()->exists()) {
                return $this->errorMessage(
                    trans('api.cannot_delete_role_with_employees') ?: 'Cannot delete role assigned to employees.',
                    400
                );
            }

            $role->permissions()->detach();
            $role->delete();

            return $this->apiResponse([], trans('api.deleted_successfully'));
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred') . ': ' . $e->getMessage(), 500);
        }
    }

    public function assignPermissions(Request $request, $id)
    {
        try {
            $role = Role::query()->find($id);

            if (! $role) {
                return $this->errorMessage(trans('api.not_found'), 404);
            }

            $validated = $request->validate([
                'permissions' => ['required_without:permission_ids', 'array'],
                'permissions.*' => ['integer', 'exists:permissions,id'],
                'permission_ids' => ['required_without:permissions', 'array'],
                'permission_ids.*' => ['integer', 'exists:permissions,id'],
                'permission_matrix' => ['nullable', 'array'],
                'activate_all_permissions' => ['sometimes', 'boolean'],
            ]);

            $permissionIds = $this->permissionResolver->resolve(
                $validated['permission_ids'] ?? $validated['permissions'] ?? [],
                $validated['permission_matrix'] ?? null,
                (bool) ($validated['activate_all_permissions'] ?? false)
            );

            // منع تصعيد الصلاحيات: غير مدير النظام لا يمنح دوراً صلاحيات لا يملكها هو،
            // ولا يحوّل دوراً إلى دور كامل الصلاحيات. (كانت هذه النقطة محميّة في
            // store/update فقط ومفقودة هنا.)
            if ($denied = $this->denyRoleEscalation($permissionIds, true, $role, [])) {
                return $denied;
            }

            $role->permissions()->sync($permissionIds);
            $role->load(['permissions', 'employees'])->loadCount('permissions');

            return $this->apiResponse(
                new RoleDetailResource($role),
                trans('api.permissions_assigned_successfully') ?: trans('api.updated_successfully')
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int>|null
     */
    protected function resolvePermissionIdsFromValidated(array $validated, bool $explicit = true): ?array
    {
        $hasPermissionPayload = $explicit && (
            array_key_exists('permissions', $validated)
            || array_key_exists('permission_ids', $validated)
            || array_key_exists('permission_matrix', $validated)
            || ($validated['activate_all_permissions'] ?? false)
        );

        if (! $hasPermissionPayload) {
            return null;
        }

        return $this->permissionResolver->resolve(
            $validated['permission_ids'] ?? $validated['permissions'] ?? [],
            $validated['permission_matrix'] ?? null,
            (bool) ($validated['activate_all_permissions'] ?? false)
        );
    }
}
