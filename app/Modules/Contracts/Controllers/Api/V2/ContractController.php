<?php

namespace App\Modules\Contracts\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\Contract\ContractTypeRequest;
use App\Http\Requests\Api\V2\Contract\DocFeePreviewRequest;
use App\Http\Requests\Api\V2\Contract\SetContractDraftRequest;
use App\Http\Requests\Api\V2\Contract\Step1Request;
use App\Http\Requests\Api\V2\Contract\Step2Request;
use App\Http\Requests\Api\V2\Contract\Step3Request;
use App\Http\Requests\Api\V2\Contract\Step4Request;
use App\Http\Requests\Api\V2\Contract\Step5Request;
use App\Http\Requests\Api\V2\Contract\Step6Request;
use App\Http\Resources\Api\V2\Contract\Step1Resource;
use App\Http\Resources\Api\V2\Contract\Step2Resource;
use App\Http\Resources\Api\V2\Contract\Step3Resource;
use App\Http\Resources\Api\V2\Contract\Step4Resource;
use App\Http\Resources\Api\V2\Contract\Step5Resource;
use App\Http\Resources\Api\V2\Contract\Step6Resource;
use App\Http\Resources\Api\V2\ContractResource;
use App\Http\Resources\SearchResource;
use App\Http\Traits\Responser;
use App\Models\Contract;
use App\Modules\Contracts\Actions\Api\GetContractFilesAction;
use App\Modules\Contracts\Actions\Api\SearchContractsAction;
use App\Modules\Contracts\Actions\Api\V2\PreviewDocFeeAction;
use App\Modules\Contracts\Actions\Api\V2\SetContractDraftAction;
use App\Modules\Contracts\Actions\Api\V2\SoftDeleteOwnContractAction;
use App\Modules\Contracts\Actions\Api\V2\StartContractAction;
use App\Modules\Contracts\Actions\Api\V2\SubmitContractStep1Action;
use App\Modules\Contracts\Actions\Api\V2\SubmitContractStep2Action;
use App\Modules\Contracts\Actions\Api\V2\SubmitContractStep3Action;
use App\Modules\Contracts\Actions\Api\V2\SubmitContractStep4Action;
use App\Modules\Contracts\Actions\Api\V2\SubmitContractStep5Action;
use App\Modules\Contracts\Actions\Api\V2\SubmitContractStep6Action;
use App\Modules\Contracts\Actions\CalculateContractFinancialAction;
use App\Modules\Contracts\Actions\ListUserContractsAction;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    use Responser;

    public function index(Request $request, ListUserContractsAction $action)
    {
        $this->authorize('viewAny', Contract::class);

        $contracts = $action->paginateForApiV2((int) auth()->id(), [
            'search' => (string) $request->query('search', ''),
            'per_page' => (int) $request->query('per_page', 10),
            'status_id' => (int) $request->query('status_id', 0),
        ]);

        return $this->apiResponse(
            [
                'data' => ContractResource::collection($contracts),
                'pagination' => $this->paginate($contracts),
            ],
            trans('api.success')
        );
    }

    public function byStatus(Request $request, $statusId, ListUserContractsAction $action)
    {
        $request->merge(['status_id' => $statusId]);
        $this->validate($request, [
            'status_id' => 'required|integer|exists:contract_statuses,id',
        ]);

        $this->authorize('viewAny', Contract::class);

        $contracts = $action->paginateForApiV2((int) auth()->id(), [
            'status_id' => (int) $statusId,
        ]);

        return $this->apiResponse(
            [
                'data' => ContractResource::collection($contracts),
                'pagination' => $this->paginate($contracts),
            ],
            trans('api.success')
        );
    }

    public function drafts(ListUserContractsAction $action)
    {
        $this->authorize('viewAny', Contract::class);

        $contracts = $action->paginateForApiV2((int) auth()->id(), ['drafts' => true]);

        return $this->apiResponse(
            [
                'data' => ContractResource::collection($contracts),
                'pagination' => $this->paginate($contracts),
            ],
            trans('api.success')
        );
    }

    public function draftsByStatus(Request $request, $statusId, ListUserContractsAction $action)
    {
        $request->merge(['status_id' => $statusId]);
        $this->validate($request, [
            'status_id' => 'required|integer|exists:draft_contract_statuses,id',
        ]);

        $this->authorize('viewAny', Contract::class);

        $contracts = $action->paginateForApiV2((int) auth()->id(), [
            'drafts' => true,
            'draft_status_id' => (int) $statusId,
        ]);

        return $this->apiResponse(
            [
                'data' => ContractResource::collection($contracts),
                'pagination' => $this->paginate($contracts),
            ],
            trans('api.success')
        );
    }

    public function show($id)
    {
        $contract = Contract::query()
            ->ownedBy(Contract::requireApiUserId())
            ->reachedAdminOrderStep()
            ->visibleToCustomer()
            ->with([
                'realEstate',
                'contractStatus',
                'draftContractStatus',
                'receivedContract',
                'statusHistories',
                'units.unitType',
                'units.unitUsage',
                'units.realEstate',
            ])
            ->findOrFail($id);

        $this->authorize('view', $contract);

        return $this->apiResponse(new ContractResource($contract), trans('api.success'));
    }

    public function destroy($id, SoftDeleteOwnContractAction $action)
    {
        $contract = Contract::query()
            ->ownedBy(Contract::requireApiUserId())
            ->find($id);

        if ($contract) {
            $this->authorize('delete', $contract);
        }

        $outcome = $action->execute($contract);
        if (! $outcome['ok']) {
            return $this->errorMessage($outcome['message'], $outcome['code']);
        }

        return $this->successMessage(trans('api.deleted_successfully'), 200);
    }

    public function start(ContractTypeRequest $request, StartContractAction $action)
    {
        $this->authorize('create', Contract::class);

        $outcome = $action->execute($request, (int) auth()->id());
        if (! $outcome['ok']) {
            return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
        }

        $contract = $outcome['contract'];

        return $this->apiResponse(
            [
                'contract_id' => $contract->id,
                'uuid' => (string) $contract->uuid,
                'real_id' => $contract->real_id,
                'real_units_id' => $contract->real_units_id,
                'units_count' => $contract->units->count(),
                'unit_ids' => $contract->units->pluck('id')->values()->all(),
            ],
            trans('api.success')
        );
    }

    public function step1(Step1Request $request, SubmitContractStep1Action $action)
    {
        $contract = Contract::findOwnedOrFail($request->validated('id'));
        $this->authorize('update', $contract);

        $before = $this->snapshotForFix($contract);
        $outcome = $action->execute($contract, $request);
        if (! $outcome['ok']) {
            return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
        }
        $fix = $this->afterCustomerStep($outcome['contract'], $before, 1);

        return response()->json([
            'message' => trans('api.success'),
            'code' => 200,
            'success' => true,
            'data' => new Step1Resource($outcome['contract']),
            'fix' => $fix,
        ], 200);
    }

    public function step2(Step2Request $request, SubmitContractStep2Action $action)
    {
        $contract = Contract::findOwnedOrFail($request->validated('id'));
        $this->authorize('update', $contract);

        $before = $this->snapshotForFix($contract);
        $outcome = $action->execute($contract, $request);
        if (! $outcome['ok']) {
            return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
        }
        $fix = $this->afterCustomerStep($outcome['contract'], $before, 2);

        return response()->json([
            'message' => trans('api.success'),
            'code' => 200,
            'success' => true,
            'data' => new Step2Resource($outcome['contract']),
            'fix' => $fix,
        ]);
    }

    public function step3(Step3Request $request, SubmitContractStep3Action $action)
    {
        $contract = Contract::findOwnedOrFail($request->id);
        $this->authorize('update', $contract);

        $before = $this->snapshotForFix($contract);
        $outcome = $action->execute($contract, $request);
        if (! $outcome['ok']) {
            return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
        }
        $fix = $this->afterCustomerStep($outcome['contract'], $before, 3);

        return response()->json([
            'message' => trans('api.success'),
            'code' => 200,
            'success' => true,
            'data' => new Step3Resource($outcome['contract']),
            'fix' => $fix,
        ]);
    }

    public function step4(Step4Request $request, SubmitContractStep4Action $action)
    {
        $contract = Contract::findOwnedOrFail($request->id);
        $this->authorize('update', $contract);

        $before = $this->snapshotForFix($contract);
        $outcome = $action->execute($contract, $request);
        if (! $outcome['ok']) {
            return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
        }
        $fix = $this->afterCustomerStep($outcome['contract'], $before, 4);

        return response()->json([
            'message' => trans('api.success'),
            'code' => 200,
            'success' => true,
            'data' => new Step4Resource($outcome['contract']),
            'fix' => $fix,
        ]);
    }

    public function step5(Step5Request $request, SubmitContractStep5Action $action)
    {
        $contract = Contract::findOwnedOrFail($request->integer('id'));
        $this->authorize('update', $contract);

        $before = $this->snapshotForFix($contract);
        $outcome = $action->execute($contract, $request, (int) auth()->id());
        if (! $outcome['ok']) {
            return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
        }
        $fix = $this->afterCustomerStep($outcome['contract'], $before, 5);

        return response()->json([
            'message' => trans('api.success'),
            'code' => 200,
            'success' => true,
            'data' => new Step5Resource($outcome['contract']),
            'fix' => $fix,
            'units_count' => $outcome['units_count'],
        ]);
    }

    public function step6(Step6Request $request, SubmitContractStep6Action $action)
    {
        $contract = Contract::findOwnedOrFail($request->id);
        $this->authorize('update', $contract);

        $before = $this->snapshotForFix($contract);
        $outcome = $action->execute($contract, $request);
        if (! $outcome['ok']) {
            return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
        }
        $fix = $this->afterCustomerStep($outcome['contract'], $before, 6);

        return response()->json([
            'message' => trans('api.success'),
            'code' => 200,
            'success' => true,
            'data' => new Step6Resource($outcome['contract']),
            'fix' => $fix,
        ]);
    }

    /**
     * دفعة (هـ) — E4: لقطة قبل خطوة العميل (لاكتشاف الحقول المتغيّرة وحماية رقم الخطوة في وضع التصحيح).
     *
     * @return array{step: int, attributes: array<string, mixed>, fix_mode: bool}
     */
    private function snapshotForFix(Contract $contract): array
    {
        return [
            'step' => (int) $contract->step,
            'attributes' => $contract->getAttributes(),
            'fix_mode' => (bool) $contract->is_completed || (int) $contract->step >= 7,
        ];
    }

    /**
     * بعد خطوة العميل: في وضع التصحيح لا يتراجع رقم الخطوة، وأي حقل مطلوب تغيّر يحلّ طلب المرفق تلقائياً.
     *
     * @param  array{step: int, attributes: array<string, mixed>, fix_mode: bool}  $before
     * @return array{fix_mode: bool, changed_fields: list<string>, resolved_request_ids: list<int>, pending_data_requests: list<array<string, mixed>>, message: string|null}
     */
    private function afterCustomerStep(Contract $contract, array $before, int $step): array
    {
        $contract->refresh();
        $after = $contract->getAttributes();
        $changed = [];
        foreach ($after as $key => $value) {
            $old = $before['attributes'][$key] ?? null;
            $normOld = is_array($old) ? json_encode($old) : (string) ($old ?? '');
            $normNew = is_array($value) ? json_encode($value) : (string) ($value ?? '');
            if ($normOld !== $normNew && ! in_array($key, ['updated_at', 'step'], true)) {
                $changed[] = (string) $key;
            }
        }

        if ($before['fix_mode'] && (int) $contract->step < $before['step']) {
            $contract->forceFill(['step' => $before['step']])->saveQuietly();
        }

        $service = app(\App\Services\DataRequests\ContractDataRequestService::class);
        $resolved = $before['fix_mode'] ? $service->autoResolve($contract, $changed) : [];

        return [
            'fix_mode' => $before['fix_mode'],
            'changed_fields' => $changed,
            'resolved_request_ids' => array_map(static fn ($r) => (int) $r->id, $resolved),
            'pending_data_requests' => $service->pendingForCustomer($contract),
            'message' => $resolved !== [] ? 'تم الإرسال — سيراجعها الموظف.' : null,
        ];
    }

    public function docFeePreview(DocFeePreviewRequest $request, PreviewDocFeeAction $action)
    {
        $this->authorize('create', Contract::class);

        return response()->json([
            'message' => trans('api.success'),
            'code' => 200,
            'success' => true,
            'data' => $action->execute($request),
        ]);
    }

    public function setDraft(SetContractDraftRequest $request, SetContractDraftAction $action)
    {
        $contract = Contract::findOwnedOrFail($request->id);
        $this->authorize('update', $contract);

        $outcome = $action->execute($contract, $request);
        if (! $outcome['ok']) {
            return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
        }

        return response()->json([
            'message' => trans('api.success'),
            'code' => 200,
            'success' => true,
            'data' => new ContractResource($outcome['contract']),
        ]);
    }

    /**
     * GET /api/v2/getContracts/{uuid} — routed since v2 launch but the method was missing (500).
     * Same ownership-scoped behaviour as the v1 endpoint.
     */
    public function getContracts(string $uuid, GetContractFilesAction $action)
    {
        $this->authorize('viewAny', Contract::class);

        $files = $action->execute($uuid, (int) auth()->id());

        if ($files !== []) {
            return $this->apiResponse(['files' => $files], trans('api.success'));
        }

        return $this->apiResponse(null, trans('api.waitContract'));
    }

    /**
     * GET /api/v2/search/{searchTerm} — routed since v2 launch but the method was missing (500).
     */
    public function search(string $searchTerm, SearchContractsAction $action)
    {
        $this->authorize('viewAny', Contract::class);

        $contracts = $action->execute($searchTerm, (int) auth()->id());

        // لا نتائج → مصفوفة فارغة (العملاء يتوقعون قائمة، لا null). (APP-3)
        if ($contracts->isEmpty()) {
            return $this->apiResponse([], trans('api.success'));
        }

        return $this->apiResponse(SearchResource::collection($contracts), trans('api.success'));
    }

    public function financial(string $uuid, CalculateContractFinancialAction $action)
    {
        $outcome = $action->execute($uuid, (int) auth()->id(), 'v2');

        if (! $outcome['ok']) {
            return response()->json([
                'message' => 'العقد غير موجود',
                'success' => false,
                'data' => [],
            ], 404);
        }

        $this->authorize('view', $outcome['contract']);

        return response()->json([
            'status' => 'success',
            'message' => 'التفاصيل الماليه',
            'data' => $outcome['payload'],
        ], 200);
    }
}
