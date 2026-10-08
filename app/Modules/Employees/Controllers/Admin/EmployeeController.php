<?php

namespace App\Modules\Employees\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\StoreEmployeeNoteRequest;
use App\Http\Requests\Admin\V2\StoreEmployeeRequest;
use App\Http\Requests\Admin\V2\StoreEmployeeSalaryRequest;
use App\Http\Requests\Admin\V2\UpdateEmployeeRequest;
use App\Http\Resources\Admin\V2\Api\EmployeeNoteResource;
use App\Http\Resources\Admin\V2\Api\EmployeeNotesListResource;
use App\Http\Resources\Admin\V2\Api\EmployeeResource;
use App\Http\Resources\Admin\V2\Api\EmployeeSalaryResource;
use App\Http\Resources\Admin\V2\Api\SalaryResource;
use App\Http\Traits\Responser;
use App\Models\Employee;
use App\Models\Role;
use App\Support\AuthenticatedEmployee;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmployeeController extends Controller
{
    use Responser;

    protected function employeeBaseRelations(): array
    {
        return [
            'roleRelation.permissions',
            'salaries' => fn ($q) => $q->orderByDesc('created_at'),
            'notes' => fn ($q) => $q->orderByDesc('addition_date')->orderByDesc('created_at'),
            'receivedContract' => fn ($q) => $q->with('contract')->orderByDesc('created_at'),
            'refundableContract' => fn ($q) => $q->with('contract')->orderByDesc('created_at'),
        ];
    }

    protected function loadEmployeeWithFullDetails(Employee $employee): Employee
    {
        $employee->load($this->employeeBaseRelations());
        $employee->loadCount(['salaries', 'notes', 'receivedContract', 'refundableContract']);

        return $employee;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function syncRoleNameFromRoleId(array $data): array
    {
        if (! empty($data['role_id'])) {
            $role = Role::query()->find($data['role_id']);
            if ($role) {
                $data['role'] = $role->name;
            }
        }

        if (array_key_exists('work_period', $data)) {
            $data['work_period'] = Employee::normalizeWorkPeriod($data['work_period']) ?? 'morning';
        }

        return $data;
    }

    public function index(Request $request)
    {
        try {
            $query = Employee::query()
                ->with(['roleRelation.permissions'])
                ->withCount(['salaries', 'notes', 'receivedContract', 'refundableContract']);

            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                });
            }

            $sortBy = $request->input('sort_by', 'created_at');
            $allowedSort = ['created_at', 'name', 'email', 'id'];
            if (! in_array($sortBy, $allowedSort, true)) {
                $sortBy = 'created_at';
            }
            $sortOrder = strtolower((string) $request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

            $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
            $employees = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

            return $this->apiResponse([
                'items' => EmployeeResource::collection($employees),
                'pagination' => $this->paginate($employees),
            ], trans('api.success'));
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    /**
     * Prevent privilege escalation via role assignment. A non-admin employee
     * may not assign a full-access (admin) role, nor any role that carries a
     * permission the actor does not already hold. Admins may assign any role.
     * Returns an error response to short-circuit, or null to proceed.
     */
    protected function denyRoleEscalation(?int $roleId): ?\Illuminate\Http\JsonResponse
    {
        if ($roleId === null) {
            return null;
        }

        $actor = AuthenticatedEmployee::from(request());

        // Full-access (admin) actors may assign any role.
        if ($actor !== null && $actor->isSystemAdmin()) {
            return null;
        }

        $role = Role::with('permissions')->find($roleId);
        if ($role === null) {
            return null; // existence already validated by the FormRequest
        }

        // A non-admin may never grant a full-access (admin) role.
        if ($role->isFullAccess()) {
            return $this->errorMessage(trans('api.forbidden'), 403);
        }

        // The target role's active permissions must be a subset of the actor's.
        foreach ($role->permissions->where('is_active', true)->pluck('name') as $permission) {
            if ($actor === null || ! $actor->hasPermission($permission)) {
                return $this->errorMessage(trans('api.forbidden'), 403);
            }
        }

        return null;
    }

    /**
     * منع الاستيلاء على حساب مدير النظام: لا يجوز لموظف ليس مدير نظام أن يعدّل
     * (كلمة المرور/البريد/الحظر/الحذف/الحالة) موظفاً هدفه مدير نظام. مدير النظام
     * وحده يدير حسابات المديرين. يعيد رد خطأ للإيقاف أو null للمتابعة.
     */
    protected function denyModifyingSystemAdmin(Employee $target): ?\Illuminate\Http\JsonResponse
    {
        $actor = AuthenticatedEmployee::from(request());

        if ($actor !== null && $actor->isSystemAdmin()) {
            return null;
        }

        if ($target->isSystemAdmin()) {
            return $this->errorMessage(trans('api.forbidden'), 403);
        }

        return null;
    }

    public function store(StoreEmployeeRequest $request)
    {
        try {
            $data = $request->validated();

            if ($denied = $this->denyRoleEscalation($data['role_id'] ?? null)) {
                return $denied;
            }

            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            if ($request->hasFile('profile_image')) {
                $image = $request->file('profile_image');
                $imageName = time().'_'.$image->getClientOriginalName();
                $imagePath = $image->storeAs('employees/profiles', $imageName, 'public');
                $data['profile_image'] = 'storage/'.$imagePath;
            }

            $employee = Employee::create($this->syncRoleNameFromRoleId($data));
            $this->loadEmployeeWithFullDetails($employee);

            return $this->apiResponse(
                new EmployeeResource($employee),
                trans('api.employee_created_successfully'),
                201
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function show(int $id)
    {
        try {
            $employee = Employee::findOrFail($id);
            $this->loadEmployeeWithFullDetails($employee);

            return $this->apiResponse(
                new EmployeeResource($employee),
                trans('api.success')
            );
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.employee_not_found'), 404);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function update(UpdateEmployeeRequest $request, int $id)
    {
        try {
            $employee = Employee::findOrFail($id);

            if ($denied = $this->denyModifyingSystemAdmin($employee)) {
                return $denied;
            }

            $data = $request->validated();

            if ($denied = $this->denyRoleEscalation($data['role_id'] ?? null)) {
                return $denied;
            }

            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            if ($request->hasFile('profile_image')) {
                if ($employee->profile_image && Storage::disk('public')->exists(str_replace('storage/', '', $employee->profile_image))) {
                    Storage::disk('public')->delete(str_replace('storage/', '', $employee->profile_image));
                }

                $image = $request->file('profile_image');
                $imageName = time().'_'.$image->getClientOriginalName();
                $imagePath = $image->storeAs('employees/profiles', $imageName, 'public');
                $data['profile_image'] = 'storage/'.$imagePath;
            }

            $employee->update($this->syncRoleNameFromRoleId($data));
            $this->loadEmployeeWithFullDetails($employee);

            return $this->apiResponse(
                new EmployeeResource($employee),
                trans('api.employee_updated_successfully')
            );
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.employee_not_found'), 404);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function destroy(int $id)
    {
        try {
            $employee = Employee::findOrFail($id);

            if ($denied = $this->denyModifyingSystemAdmin($employee)) {
                return $denied;
            }

            if ($employee->profile_image && Storage::disk('public')->exists(str_replace('storage/', '', $employee->profile_image))) {
                Storage::disk('public')->delete(str_replace('storage/', '', $employee->profile_image));
            }

            $employee->tokens()->delete();
            $employee->delete();

            return $this->apiResponse([], trans('api.employee_deleted_successfully'));
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.employee_not_found'), 404);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function toggleStatus(int $id)
    {
        try {
            $employee = Employee::findOrFail($id);

            if ($denied = $this->denyModifyingSystemAdmin($employee)) {
                return $denied;
            }

            $employee->update(['is_active' => ! $employee->is_active]);
            $employee->load($this->employeeBaseRelations());

            return $this->apiResponse(
                new EmployeeResource($employee),
                trans('api.employee_status_updated')
            );
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.employee_not_found'), 404);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function block(Request $request, int $id)
    {
        try {
            $request->validate([
                'blocked_until' => ['required', 'date', 'after:now'],
                'reason_of_block' => ['required', 'string'],
            ]);

            $employee = Employee::findOrFail($id);

            if ($denied = $this->denyModifyingSystemAdmin($employee)) {
                return $denied;
            }

            $employee->update([
                'blocked_until' => $request->input('blocked_until'),
                'reason_of_block' => $request->input('reason_of_block'),
                'is_active' => false,
            ]);
            $employee->load($this->employeeBaseRelations());

            return $this->apiResponse(
                new EmployeeResource($employee),
                trans('api.employee_blocked_successfully')
            );
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.employee_not_found'), 404);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function unblock(int $id)
    {
        try {
            $employee = Employee::findOrFail($id);

            if ($denied = $this->denyModifyingSystemAdmin($employee)) {
                return $denied;
            }

            $employee->update([
                'blocked_until' => null,
                'reason_of_block' => null,
                'is_active' => true,
            ]);
            $employee->load($this->employeeBaseRelations());

            return $this->apiResponse(
                new EmployeeResource($employee),
                trans('api.employee_unblocked_successfully')
            );
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.employee_not_found'), 404);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function storeNote(StoreEmployeeNoteRequest $request, int $id)
    {
        try {
            $employee = Employee::findOrFail($id);

            $note = $employee->notes()->create([
                'addition_date' => $request->date('addition_date'),
                'notes_by_manger' => $request->input('note'),
            ]);

            return $this->apiResponse(
                new EmployeeNoteResource($note),
                trans('api.employee_note_created_successfully'),
                201
            );
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.employee_not_found'), 404);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function employeeNotes(Request $request)
    {
        try {
            $validated = $request->validate([
                'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);

            $query = Employee::query()
                ->select(['id', 'name', 'email', 'phone'])
                ->with(['notes' => fn ($q) => $q->orderByDesc('addition_date')->orderByDesc('created_at')]);

            if (! empty($validated['employee_id'])) {
                $query->where('id', $validated['employee_id']);
            }

            $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
            $employees = $query->latest()->paginate($perPage);

            return $this->apiResponse(
                [
                    'items' => EmployeeNotesListResource::collection($employees),
                    'pagination' => $this->paginate($employees),
                ],
                trans('api.success')
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function storeSalary(StoreEmployeeSalaryRequest $request, int $id)
    {
        try {
            $employee = Employee::findOrFail($id);

            $data = $request->validated();
            $data['month'] = $request->date('due_date')->format('Y-m');

            $salary = $employee->salaries()->create($data);

            return $this->apiResponse(
                new SalaryResource($salary),
                trans('api.employee_salary_created_successfully'),
                201
            );
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.employee_not_found'), 404);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function employeeSalary(Request $request)
    {
        try {
            $validated = $request->validate([
                'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);

            $query = Employee::query()
                ->select(['id', 'name', 'email', 'phone', 'base_salary'])
                ->with(['salaries' => fn ($q) => $q->orderByDesc('created_at')]);

            if (! empty($validated['employee_id'])) {
                $query->where('id', $validated['employee_id']);
            }

            $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
            $query->latest();
            $totalBaseSalary = (clone $query)->sum('base_salary');
            $employees = $query->paginate($perPage);

            return $this->apiResponse(
                [
                    'items' => EmployeeSalaryResource::collection($employees),
                    'total_base_salary' => $totalBaseSalary,
                    'pagination' => $this->paginate($employees),
                ],
                trans('api.success')
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }
}
