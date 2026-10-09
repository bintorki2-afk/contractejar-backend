<?php

namespace App\Modules\Notifications\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\Responser;
use App\Models\Employee;
use App\Models\EmployeeNotification;
use Illuminate\Http\Request;

/**
 * دفعة (هـ): إشعارات اللوحة للموظف الحالي (رد العميل على طلب مرفق، دفع رسوم…).
 */
class EmployeeNotificationController extends Controller
{
    use Responser;

    /** GET /api/admin/employee-notifications?unread=1&per_page=20 */
    public function index(Request $request)
    {
        $employee = $request->user();
        if (! $employee instanceof Employee) {
            return $this->errorMessage(trans('api.unauthorized'), 403);
        }

        $query = EmployeeNotification::query()->with('contract:id,uuid')
            ->where(fn ($q) => $q->whereNull('employee_id')->orWhere('employee_id', $employee->id))
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('id');

        $paginator = $query->paginate(min(max((int) $request->input('per_page', 20), 1), 100));
        $unread = EmployeeNotification::query()
            ->where(fn ($q) => $q->whereNull('employee_id')->orWhere('employee_id', $employee->id))
            ->whereNull('read_at')->count();

        return $this->paginatedApiResponse(
            $paginator,
            collect($paginator->items())->map(fn (EmployeeNotification $n) => $n->toArrayForAdmin())->values(),
            trans('api.success'),
            ['unread_count' => $unread],
        );
    }

    /** POST /api/admin/employee-notifications/{id}/read */
    public function read(Request $request, int $id)
    {
        $employee = $request->user();
        if (! $employee instanceof Employee) {
            return $this->errorMessage(trans('api.unauthorized'), 403);
        }
        $row = EmployeeNotification::query()
            ->where(fn ($q) => $q->whereNull('employee_id')->orWhere('employee_id', $employee->id))
            ->findOrFail($id);
        if ($row->read_at === null) {
            $row->forceFill(['read_at' => now()])->save();
        }

        return $this->apiResponse($row->fresh('contract')->toArrayForAdmin(), trans('api.success'));
    }

    /** POST /api/admin/employee-notifications/read-all */
    public function readAll(Request $request)
    {
        $employee = $request->user();
        if (! $employee instanceof Employee) {
            return $this->errorMessage(trans('api.unauthorized'), 403);
        }
        $updated = EmployeeNotification::query()
            ->where(fn ($q) => $q->whereNull('employee_id')->orWhere('employee_id', $employee->id))
            ->whereNull('read_at')->update(['read_at' => now()]);

        return $this->apiResponse(['updated' => $updated, 'unread_count' => 0], trans('api.success'));
    }
}
