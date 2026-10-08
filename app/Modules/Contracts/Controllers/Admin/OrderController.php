<?php

namespace App\Modules\Contracts\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateContractRequest;
use App\Http\Requests\Admin\UpdateReturnContractAcceptanceRequest;
use App\Http\Resources\Admin\V2\Api\OrderResource;
use App\Http\Traits\Responser;
use App\Models\Contract;
use App\Models\Payment;
use App\Modules\Contracts\Actions\SetReturnContractAcceptanceAction;
use App\Modules\Contracts\Actions\UpdateAdminContractAction;
use App\Modules\Contracts\Actions\UpdateAdminContractStatusAction;
use App\Modules\Contracts\Services\AdminOrderDetailService;
use App\Modules\Contracts\Services\AdminOrderQueryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class OrderController extends Controller
{
    use Responser;

    public function __construct(
        private readonly AdminOrderQueryService $orders,
        private readonly AdminOrderDetailService $details,
        private readonly UpdateAdminContractStatusAction $updateStatus,
        private readonly UpdateAdminContractAction $updateContract,
        private readonly SetReturnContractAcceptanceAction $returnAcceptance,
    ) {}

    /**
     * Single orders list. Filter with query params — do not use a second list URL.
     *
     * GET /api/admin/orders
     */
    public function orders(Request $request)
    {
        try {
            $result = $this->orders->paginateOrders($request);

            return $this->paginatedApiResponse(
                $result['paginator'],
                OrderResource::collection($result['paginator']),
                trans('api.success'),
                $result['meta']
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->apiResponse(
                null,
                trans('api.error_occurred').': '.$e->getMessage(),
                false,
                500
            );
        }
    }

    /**
     * GET /api/admin/orders/status-counts — عدّادات التبويبات (نفس نطاق القائمة وفلاترها).
     */
    public function statusCounts(Request $request)
    {
        return $this->apiResponse($this->orders->statusCounts($request), trans('api.success'));
    }

    /**
     * GET /api/admin/orders/trash — الطلبات في السلة (الأحدث أولاً).
     */
    public function trash(Request $request)
    {
        $trash = app(\App\Services\Orders\TrashService::class);
        $paginator = Contract::query()
            ->where('is_delete', 1)->whereNotNull('trashed_at')
            ->when($request->filled('search'), fn ($q) => $q->adminSearch($request->string('search')->toString()))
            ->with($this->orders->orderListRelations())
            ->latest('trashed_at')
            ->paginate(min(max((int) $request->input('per_page', 50), 1), 200));

        $items = collect($paginator->items())->map(fn (Contract $c) => array_merge(
            (new OrderResource($c))->toArray($request),
            $trash->trashMeta($c->trashed_at, $c->deleted_by),
        ))->values();

        return $this->paginatedApiResponse($paginator, $items, trans('api.success'), ['retention_days' => \App\Services\Orders\TrashService::RETENTION_DAYS]);
    }

    /**
     * POST /api/admin/orders/{id}/restore
     */
    public function restore(Request $request, int $id)
    {
        try {
            $contract = app(\App\Services\Orders\TrashService::class)->restoreContract(
                Contract::query()->findOrFail($id),
                $request->user() instanceof \App\Models\Employee ? $request->user() : null,
            );

            return $this->apiResponse(['id' => $contract->id, 'uuid' => (string) $contract->uuid, 'is_delete' => false], 'تمت استعادة الطلب.');
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        }
    }

    /**
     * GET /api/admin/orders/attention — «عليك الحين» (الأقدم أولاً).
     */
    public function attention(Request $request)
    {
        $limit = min(max((int) $request->input('limit', 50), 1), 200);

        return $this->apiResponse(app(\App\Services\Orders\OrderAttentionService::class)->board($limit), trans('api.success'));
    }

    /**
     * POST /api/admin/orders/{id}/stage/{received|draft_sent|notarized}
     */
    public function stage(Request $request, int $id, string $stage)
    {
        $employee = $request->user();
        if (! $employee instanceof \App\Models\Employee) {
            return $this->errorMessage(trans('api.unauthorized'), 403);
        }

        try {
            $result = app(\App\Services\Orders\OrderStageService::class)
                ->run($request, Contract::query()->where('is_delete', 0)->findOrFail($id), $stage, $employee);

            return $this->apiResponse($result, trans('api.success'));
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? $e->getMessage(),
                'errors' => $e->errors(),
                'code' => 422,
                'success' => false,
            ], 422);
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.contract_not_found'), 404);
        }
    }

    /**
     * PATCH /api/admin/orders/{id} — تعديل حقول صغيرة (هويات، أسماء، تواريخ، مبالغ) مع سجل قبل/بعد.
     */
    public function patchFields(Request $request, int $id)
    {
        try {
            $result = app(\App\Services\Orders\AdminOrderPatchService::class)->patch(
                Contract::query()->findOrFail($id),
                $request->except(['_method']),
                $request->user() instanceof \App\Models\Employee ? $request->user() : null,
            );

            return $this->apiResponse($result, $result['changed'] === [] ? 'لا توجد تغييرات.' : trans('api.success'));
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors(), 'code' => 422, 'success' => false], 422);
        }
    }

    /** GET /api/admin/orders/editable-fields — الحقول المسموح تعديلها مضمّنة (PATCH). */
    public function editableFields()
    {
        return $this->apiResponse(collect(\App\Services\Orders\AdminOrderPatchService::FIELDS)
            ->map(fn ($f, $k) => ['key' => $k, 'label' => $f['label'], 'rules' => $f['rules']])->values(), trans('api.success'));
    }

    /**
     * GET /api/admin/orders/{id}/ejar-copy[?format=text] — كتل بترتيب إدخال إيجار.
     */
    public function ejarCopy(Request $request, int $id)
    {
        $payload = app(\App\Services\Orders\EjarCopyService::class)->build(Contract::query()->findOrFail($id));

        if ($request->query('format') === 'text') {
            return response($payload['text'], 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return $this->apiResponse($payload, trans('api.success'));
    }

    /**
     * GET /api/admin/orders/{id}/stages — المرحلة الحالية والتالية وحقولها (لأزرار المراحل في اللوحة).
     */
    public function stages(int $id)
    {
        $contract = Contract::query()->findOrFail($id);
        $service = app(\App\Services\Orders\OrderStageService::class);
        $key = \App\Models\ContractStatus::keyForId((int) $contract->contract_status_id);
        $received = $contract->receivedContract()->exists();
        $current = match (true) {
            in_array($key, ['ejar_authenticated', 'completed'], true) => 'notarized',
            $key === 'whatsapp_draft' => 'draft_sent',
            $received => 'received',
            default => null,
        };
        $next = $current === null ? 'received' : \App\Services\Orders\OrderStageService::NEXT[$current];

        return $this->apiResponse([
            'current_stage' => $current,
            'next_stage' => $next,
            'next_stage_label' => $next ? \App\Services\Orders\OrderStageService::LABELS[$next] : null,
            'next_stage_required_fields' => $service->requiredFields($next),
            'stages' => collect(\App\Services\Orders\OrderStageService::STAGES)->map(fn ($s) => [
                'key' => $s, 'label' => \App\Services\Orders\OrderStageService::LABELS[$s],
                'done' => $current !== null && array_search($s, \App\Services\Orders\OrderStageService::STAGES, true) <= array_search($current, \App\Services\Orders\OrderStageService::STAGES, true),
            ])->values(),
            'customer_phone' => $service->customerPhone($contract),
        ], trans('api.success'));
    }

    /**
     * POST /api/admin/orders/{id}/notify { kind: data_missing|status_changed, message?, step? }
     */
    public function notifyCustomer(Request $request, int $id)
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:data_missing,status_changed'],
            'message' => ['nullable', 'string', 'max:1000'],
            'step' => ['nullable', 'integer', 'min:1', 'max:7'],
        ]);

        $contract = Contract::query()->findOrFail($id);
        $customers = app(\App\Services\CustomerNotificationService::class);
        $offer = $validated['kind'] === 'data_missing'
            ? $customers->dataMissing($contract, $validated['message'] ?? null, isset($validated['step']) ? (int) $validated['step'] : null)
            : $customers->notify(
                $contract->user ?? throw new InvalidArgumentException('لا يوجد عميل مرتبط بالطلب.'),
                \App\Services\CustomerNotificationService::KIND_STATUS_CHANGED,
                'تحديث حالة طلبك',
                'طلبك رقم '.$contract->uuid.': '.(\App\Support\ContractFrontendStatus::for($contract)['status_label'] ?? ''),
                [],
                $contract,
                dedupe: false,
            );

        app(\App\Services\Orders\OrderFlowService::class)->activity(
            $contract, 'notification_sent', $request->user() instanceof \App\Models\Employee ? $request->user() : null,
            null, ['kind' => $validated['kind'], 'step' => $validated['step'] ?? null], 'employee', $validated['message'] ?? null,
        );

        return $this->apiResponse([
            'stored' => $offer !== null,
            'notification_id' => $offer?->id,
            'notifications_sent' => $customers->sentForContract($contract),
        ], trans('api.success'));
    }

    public function returnOrders(Request $request)
    {
        try {
            $result = $this->orders->paginateReturnOrders($request);

            return $this->paginatedApiResponse(
                $result['paginator'],
                OrderResource::collection($result['paginator']),
                trans('api.success'),
                $result['meta']
            );
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->apiResponse(
                null,
                trans('api.error_occurred').': '.$e->getMessage(),
                false,
                500
            );
        }
    }

    public function receivedOrders(Request $request)
    {
        try {
            $result = $this->orders->paginateReceivedOrders($request);

            return $this->paginatedApiResponse(
                $result['paginator'],
                OrderResource::collection($result['paginator']),
                trans('api.success'),
                $result['meta']
            );
        } catch (\Throwable $e) {
            return $this->apiResponse(
                null,
                trans('api.error_occurred').': '.$e->getMessage(),
                false,
                500
            );
        }
    }

    public function byStatus(Request $request, $statusId)
    {
        $request->merge(['status_id' => $statusId]);

        return $this->orders($request);
    }

    public function draftByStatus(Request $request, $statusId)
    {
        $request->merge([
            'is_draft' => 1,
            'status_id' => $statusId,
            'draft_contract_status_id' => $statusId,
        ]);

        return $this->orders($request);
    }

    public function incomplete(Request $request)
    {
        $request->merge(['incomplete' => 1]);

        return $this->orders($request);
    }

    public function draftContracts(Request $request)
    {
        try {
            $result = $this->orders->paginateDraftOrders($request);

            return $this->paginatedApiResponse(
                $result['paginator'],
                OrderResource::collection($result['paginator']),
                trans('api.success'),
                $result['meta']
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->apiResponse(
                null,
                trans('api.error_occurred').': '.$e->getMessage(),
                false,
                500
            );
        }
    }

    public function completedAndDraft(Request $request)
    {
        try {
            $result = $this->orders->paginateCompletedAndDraft($request);

            return $this->paginatedApiResponse(
                $result['paginator'],
                OrderResource::collection($result['paginator']),
                trans('api.success'),
                $result['meta']
            );
        } catch (\Throwable $e) {
            return $this->apiResponse(
                null,
                trans('api.error_occurred').': '.$e->getMessage(),
                false,
                500
            );
        }
    }

    public function complete(Request $request)
    {
        $request->merge(['complete' => 1]);

        return $this->orders($request);
    }

    public function show(Request $request, $id)
    {
        $contract = $this->orders->findAdminContract((int) $id);

        return $this->apiResponse(
            $this->details->fullPayload($contract, $request),
            trans('api.success')
        );
    }

    public function updateStatus(Request $request, $id)
    {
        return $this->handleStatusOutcome(
            fn () => $this->updateStatus->execute($request, (int) $id),
            $request
        );
    }

    public function updateContractStatus(Request $request, $id)
    {
        return $this->handleStatusOutcome(function () use ($request, $id) {
            $contract = $this->orders->findAdminContract((int) $id);

            return $this->updateStatus->updateLive($request, $contract);
        }, $request);
    }

    public function updateDraftContractStatus(Request $request, $id)
    {
        return $this->handleStatusOutcome(function () use ($request, $id) {
            $contract = $this->orders->findAdminContract((int) $id);

            return $this->updateStatus->updateDraft($request, $contract);
        }, $request);
    }

    public function update(UpdateContractRequest $request, $id)
    {
        try {
            $contract = $this->updateContract->execute($request, (int) $id);

            return $this->apiResponse(
                $this->details->fullPayload($contract, $request),
                trans('api.contract_updated_successfully')
            );
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(null, trans('api.contract_not_found'), false, 404);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            report($e);

            return $this->apiResponse(
                null,
                trans('api.error_occurred').(config('app.debug') ? ': '.$e->getMessage() : ''),
                false,
                500
            );
        }
    }

    public function updateReturnContractAcceptance(UpdateReturnContractAcceptanceRequest $request, int $id)
    {
        try {
            $outcome = $this->returnAcceptance->execute(
                $request,
                $id,
                $request->boolean('accept_retrun_contract')
            );

            if (! $outcome['ok']) {
                return $this->errorMessage($outcome['message'], $outcome['code'] ?? 422);
            }

            return $this->apiResponse(
                $this->details->fullPayload($outcome['contract'], $request),
                $request->boolean('accept_retrun_contract')
                    ? trans('api.return_contract_accepted_successfully')
                    : trans('api.return_contract_rejected_successfully')
            );
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(null, trans('api.contract_not_found'), false, 404);
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    /**
     * Delete a single order (contract) and its related rows.
     *
     * Related tables that reference contracts.id are removed automatically by
     * the database ON DELETE CASCADE / SET NULL foreign keys. Payment rows are
     * linked by contract_uuid (no FK), so we remove them explicitly. Everything
     * runs inside a transaction: if any step fails, nothing is deleted.
     *
     * POST /api/admin/orders/{id}/delete
     */
    public function destroy(Request $request, $id)
    {
        try {
            $contract = $this->orders->findAdminContract((int) $id);

            // منع حذف طلب مدفوع (له دفعة ناجحة) حفاظاً على الأثر المالي؛ مدير النظام
            // يتجاوز بـ force=1 (يُسجَّل). (DASHBOARD-3)
            $hasSuccessfulPayment = ! empty($contract->uuid)
                && Payment::query()->successfulMatchingContractUuid((string) $contract->uuid)->exists();

            $actor = \App\Support\AuthenticatedEmployee::from($request);
            $isSystemAdmin = $actor !== null && $actor->isSystemAdmin();

            if ($hasSuccessfulPayment && ! ($isSystemAdmin && $request->boolean('force'))) {
                return $this->apiResponse(null, trans('api.cannot_delete_paid_order'), false, 422);
            }

            if ($hasSuccessfulPayment && $isSystemAdmin && $request->boolean('force')) {
                \Illuminate\Support\Facades\Log::warning('System admin force-deleted a paid order', [
                    'contract_id' => $contract->id,
                    'uuid' => $contract->uuid,
                    'employee_id' => $actor?->id,
                ]);
            }

            // دفعة (د) — ب12: نقل للسلة (استعادة خلال 30 يوماً) بدل الحذف النهائي، والدفعات لا تُحذف.
            $contract = app(\App\Services\Orders\TrashService::class)->trashContract($contract, $actor);

            return $this->apiResponse([
                'id' => $contract->id,
                'uuid' => (string) $contract->uuid,
                'message' => 'نُقل الطلب إلى السلة — يمكنك التراجع خلال 30 يوماً.',
                ...app(\App\Services\Orders\TrashService::class)->trashMeta($contract->trashed_at, $contract->deleted_by),
            ], trans('api.success'), true, 200);
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(null, trans('api.contract_not_found'), false, 404);
        } catch (\Throwable $e) {
            return $this->apiResponse(
                null,
                trans('api.error_occurred').': '.$e->getMessage(),
                false,
                500
            );
        }
    }

    /**
     * @param  callable(): array{ok: bool, contract?: Contract, message?: string, errors?: mixed, code?: int}  $callback
     */
    private function handleStatusOutcome(callable $callback, Request $request)
    {
        try {
            $outcome = $callback();

            if (! $outcome['ok']) {
                if (! empty($outcome['errors'])) {
                    return $this->errorResponse($outcome['errors'], $outcome['code'] ?? 422);
                }

                return $this->errorMessage($outcome['message'] ?? trans('api.error_occurred'), $outcome['code'] ?? 422);
            }

            return $this->apiResponse(
                $this->details->fullPayload($outcome['contract'], $request),
                trans('api.updated_successfully')
            );
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(null, trans('api.contract_not_found'), false, 404);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->apiResponse(
                null,
                trans('api.error_occurred').': '.$e->getMessage(),
                false,
                500
            );
        }
    }

    /**
     * 422 مع `message` (أول خطأ) إضافةً إلى `errors` — حتى تعرض اللوحة رسالة الخادم مباشرة
     * (مثل قاعدة «لا يمكن توثيق العقد قبل إرسال المسودة للعميل عبر واتساب»).
     */
    private function validationErrorResponse(ValidationException $e)
    {
        $errors = $e->errors();
        $first = collect($errors)->flatten()->first();

        return $this->jsonResponse([
            'message' => is_string($first) && $first !== '' ? $first : trans('api.error_occurred'),
            'code' => 422,
            'success' => false,
            'errors' => $errors,
        ], 422);
    }
}
