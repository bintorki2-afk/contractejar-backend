<?php

namespace App\Modules\LessorChange\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LessorChangeRequest;
use App\Services\CustomerNotificationService;
use App\Shared\Responses\Responser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * لوحة التحكم: طلبات تغيير المؤجر.
 */
class LessorChangeAdminController extends Controller
{
    use Responser;

    public function index(Request $request)
    {
        $query = LessorChangeRequest::query()
            ->with(['user:id,name,mobile,contact_mobile', 'employee:id,name'])
            ->where('is_delete', false)
            ->latest('id');

        if ($request->filled('status') && in_array($request->status, LessorChangeRequest::STATUSES, true)) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $term = trim((string) $request->search);
            $query->where(fn ($q) => $q->where('uuid', 'like', "%{$term}%")
                ->orWhere('mobile', 'like', "%{$term}%")
                ->orWhere('new_owner_id_number', 'like', "%{$term}%"));
        }

        $rows = $query->paginate((int) $request->input('per_page', 20));
        $rows->getCollection()->transform(fn (LessorChangeRequest $r) => $this->adminRow($r));

        return $this->apiResponse([
            'items' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
            'statuses' => collect(LessorChangeRequest::STATUSES)->map(fn ($s) => [
                'value' => $s,
                'label' => LessorChangeRequest::STATUS_LABELS[$s],
                'color' => LessorChangeRequest::STATUS_COLORS[$s],
            ])->values(),
            'counts' => LessorChangeRequest::query()->where('is_delete', false)
                ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
        ], trans('api.success'));
    }

    public function show(int $id)
    {
        $row = LessorChangeRequest::query()->with(['user', 'employee:id,name'])->findOrFail($id);

        return $this->apiResponse($this->adminRow($row, true), trans('api.success'));
    }

    public function updateStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(LessorChangeRequest::STATUSES)],
            'status_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $row = LessorChangeRequest::query()->findOrFail($id);
        $payload = [
            'status' => $data['status'],
            'status_note' => $data['status_note'] ?? $row->status_note,
            'employee_id' => optional($request->user())->id ?? $row->employee_id,
        ];
        if ($data['status'] === 'completed' && ! $row->completed_at) {
            $payload['completed_at'] = now();
        }
        if ($data['status'] === 'paid' && ! $row->paid_at) {
            $payload['paid_at'] = now();
        }
        $statusChanged = $row->status !== $data['status'];
        $row->update($payload);

        if ($statusChanged) {
            // ف8: أي تغيير في حالة طلب تغيير المؤجر يصل العميل (صندوق الإشعارات + Push).
            app(CustomerNotificationService::class)->lessorChangeStatusChanged($row->fresh(['user']));
        }

        return $this->apiResponse($this->adminRow($row->fresh(['user', 'employee']), true), trans('api.updated_successfully'));
    }

    public function destroy(Request $request, int $id)
    {
        // دفعة (د) — ب12: نقل للسلة (استعادة خلال 30 يوماً).
        $trash = app(\App\Services\Orders\TrashService::class);
        $row = $trash->trashLessorChange(LessorChangeRequest::query()->findOrFail($id), $request->user() instanceof \App\Models\Employee ? $request->user() : null);

        return $this->apiResponse(array_merge(['id' => $row->id, 'uuid' => (string) $row->uuid], $trash->trashMeta($row->trashed_at, $row->deleted_by)), trans('api.deleted_successfully'));
    }

    public function trash(Request $request)
    {
        $trash = app(\App\Services\Orders\TrashService::class);
        $rows = LessorChangeRequest::query()->with(['user:id,name,mobile,contact_mobile', 'employee:id,name'])
            ->where('is_delete', true)->whereNotNull('trashed_at')->latest('trashed_at')
            ->paginate((int) $request->input('per_page', 50));
        $rows->getCollection()->transform(fn (LessorChangeRequest $r) => array_merge($this->adminRow($r), $trash->trashMeta($r->trashed_at, $r->deleted_by)));

        return $this->apiResponse([
            'items' => $rows->items(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'retention_days' => \App\Services\Orders\TrashService::RETENTION_DAYS],
        ], trans('api.success'));
    }

    public function restore(Request $request, int $id)
    {
        try {
            $row = app(\App\Services\Orders\TrashService::class)->restoreLessorChange(LessorChangeRequest::query()->findOrFail($id), $request->user() instanceof \App\Models\Employee ? $request->user() : null);

            return $this->apiResponse(['id' => $row->id, 'is_delete' => false], 'تمت الاستعادة.');
        } catch (\InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        }
    }

    private function adminRow(LessorChangeRequest $r, bool $withImages = false): array
    {
        $base = $r->toClientArray();
        $base['notes'] = $r->notes;
        $base['mobile'] = $r->mobile;
        $base['platform'] = $r->platform;
        $base['user'] = $r->user ? [
            'id' => $r->user->id,
            'name' => $r->user->name,
            'mobile' => $r->user->contact_mobile ?? $r->user->mobile,
        ] : null;
        $base['employee'] = $r->employee ? ['id' => $r->employee->id, 'name' => $r->employee->name] : null;
        $base['created_at'] = optional($r->created_at)->format('Y-m-d H:i');
        if ($withImages) {
            $base['old_deed_image_url'] = $r->signedImageUrl('old_deed_image');
            $base['new_deed_image_url'] = $r->signedImageUrl('new_deed_image');
        }

        return $base;
    }
}
