<?php

namespace App\Modules\Contracts\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreContractCommentRequest;
use App\Http\Requests\Admin\UpdateContractCommentRequest;
use App\Http\Resources\Admin\V2\Api\ContractCommentResource;
use App\Http\Traits\Responser;
use App\Models\Contract;
use App\Models\ContractComment;
use App\Models\Employee;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ContractCommentController extends Controller
{
    use Responser;

    public function index(Request $request, int $contractId)
    {
        try {
            Contract::findForStaffOrFail($contractId);

            $comments = ContractComment::query()
                ->where('contract_id', $contractId)
                ->with('employee')
                ->latest()
                ->paginate($this->perPageFromRequest($request));

            return $this->apiResponse([
                'items' => ContractCommentResource::collection($comments),
                'pagination' => $this->paginate($comments),
            ], trans('api.success'));
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.contract_not_found'), 404);
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred') . ': ' . $e->getMessage(), 500);
        }
    }

    public function store(StoreContractCommentRequest $request, int $contractId)
    {
        try {
            Contract::findForStaffOrFail($contractId);
            $employee = $this->resolveAuthenticatedEmployee($request);
            if (! $employee) {
                return $this->errorMessage(trans('api.unauthorized'), 403);
            }

            $comment = ContractComment::query()->create([
                'contract_id' => $contractId,
                'employee_id' => $employee->id,
                'comment' => $request->validated('comment'),
            ]);
            $comment->load('employee');

            // دفعة (د) — ب9: سجل النشاط.
            app(\App\Services\Orders\OrderFlowService::class)->activity(
                Contract::query()->find($contractId), 'note_added', $employee, null, ['comment_id' => $comment->id], 'employee',
                mb_strimwidth((string) $comment->comment, 0, 300, '…'),
            );

            return $this->apiResponse(
                new ContractCommentResource($comment),
                trans('api.created_successfully'),
                201
            );
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.contract_not_found'), 404);
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred') . ': ' . $e->getMessage(), 500);
        }
    }

    public function update(UpdateContractCommentRequest $request, int $contractId, int $commentId)
    {
        try {
            Contract::findForStaffOrFail($contractId);
            $employee = $this->resolveAuthenticatedEmployee($request);
            if (! $employee) {
                return $this->errorMessage(trans('api.unauthorized'), 403);
            }

            $comment = ContractComment::query()
                ->where('contract_id', $contractId)
                ->findOrFail($commentId);

            if ((int) $comment->employee_id !== (int) $employee->id) {
                return $this->errorMessage(trans('api.unauthorized'), 403);
            }

            $comment->update([
                'comment' => $request->validated('comment'),
            ]);
            $comment->load('employee');

            return $this->apiResponse(
                new ContractCommentResource($comment),
                trans('api.updated_successfully')
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred') . ': ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Request $request, int $contractId, int $commentId)
    {
        try {
            Contract::findForStaffOrFail($contractId);
            $employee = $this->resolveAuthenticatedEmployee($request);
            if (! $employee) {
                return $this->errorMessage(trans('api.unauthorized'), 403);
            }

            $comment = ContractComment::query()
                ->where('contract_id', $contractId)
                ->findOrFail($commentId);

            if ((int) $comment->employee_id !== (int) $employee->id) {
                return $this->errorMessage(trans('api.unauthorized'), 403);
            }

            $comment->delete();

            return $this->apiResponse([], trans('api.deleted_successfully'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * Ensures the current sanctum token belongs to an employee.
     */
    private function resolveAuthenticatedEmployee(Request $request): ?Employee
    {
        $user = $request->user();
        return $user instanceof Employee ? $user : null;
    }
}
