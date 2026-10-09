<?php

namespace App\Modules\Payments\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V2\InvoiceResource;
use App\Http\Traits\Responser;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\LessorChangeRequest;
use App\Services\ContractInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class InvoiceController extends Controller
{
    use Responser;

    public function __construct(private readonly ContractInvoiceService $invoices)
    {
    }

    /**
     * List invoices for the authenticated user (contracts + lessor-change requests).
     * GET /api/v2/invoices
     */
    public function index(Request $request)
    {
        $userId = (int) auth()->id();

        $this->ensureInvoiceRowsFor($userId);

        $paginator = Invoice::query()
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->whereHas('contract', fn ($c) => $c->visibleToOwner())
                    ->orWhere('kind', Invoice::KIND_LESSOR_CHANGE);
            })
            ->with([
                'contract' => fn ($q) => $q->with(['user', 'contractStatus', 'refundableContract']),
                'lessorChangeRequest.user',
            ])
            ->orderByDesc('id')
            ->paginate($this->perPageFromRequest($request, 10));

        $items = collect($paginator->items())
            ->map(fn (Invoice $invoice) => $this->invoices->forInvoice($invoice))
            ->filter()
            ->values();

        return $this->apiResponse(
            [
                'data' => InvoiceResource::collection($items),
                'pagination' => $this->paginate($paginator),
            ],
            trans('api.success')
        );
    }

    /**
     * Invoice for a contract (by contracts.id).
     * GET /api/v2/invoices/{contractId}
     * GET /api/v2/contracts/{contractId}/invoice
     */
    public function show(int $contractId)
    {
        $contract = $this->findOwnedContract($contractId);

        if (! $contract) {
            return $this->errorMessage(trans('api.contract_not_found'), 404);
        }

        return $this->apiResponse(
            new InvoiceResource($this->invoices->forContract($contract)),
            trans('api.success')
        );
    }

    /**
     * Invoice by invoice_number (e.g. INV-47990 or INV-LC-12).
     * GET /api/v2/invoices/number/{invoiceNumber}
     */
    public function showByNumber(string $invoiceNumber)
    {
        $invoiceNumber = urldecode($invoiceNumber);

        $invoice = Invoice::query()
            ->where('invoice_number', $invoiceNumber)
            ->first();

        if (! $invoice) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        if ($invoice->isLessorChange()) {
            $request = LessorChangeRequest::query()
                ->whereKey($invoice->lessor_change_request_id)
                ->where('user_id', auth()->id())
                ->where('is_delete', false)
                ->first();

            if (! $request) {
                return $this->errorMessage(trans('api.not_found'), 404);
            }

            return $this->apiResponse(
                new InvoiceResource($this->invoices->forLessorChange($request)),
                trans('api.success')
            );
        }

        if (! $invoice->contract_id) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        $contract = $this->findOwnedContract((int) $invoice->contract_id);

        if (! $contract) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        return $this->apiResponse(
            new InvoiceResource($this->invoices->forContract($contract)),
            trans('api.success')
        );
    }

    /**
     * Invoice rows are issued lazily (first view). For the list we issue the missing ones
     * for the user's paid / refunded contracts and paid lessor-change requests first, so the
     * paginated query over `invoices` is complete.
     */
    private function ensureInvoiceRowsFor(int $userId): void
    {
        Contract::query()
            ->where('user_id', $userId)
            ->where('is_delete', 0)
            ->where(function ($q) {
                $q->where('is_completed', 1)->orWhereHas('refundableContract');
            })
            ->whereDoesntHave('invoices')
            ->with(['user', 'contractStatus', 'refundableContract'])
            ->orderBy('id')
            ->each(fn (Contract $contract) => $this->invoices->ensureInvoiceRecord($contract));

        // فواتير قديمة بدون user_id (قبل هذا التحديث).
        Invoice::query()
            ->whereNull('user_id')
            ->whereIn('contract_id', Contract::query()->where('user_id', $userId)->select('id'))
            ->update(['user_id' => $userId]);

        if (Schema::hasTable('lessor_change_requests')) {
            LessorChangeRequest::query()
                ->where('user_id', $userId)
                ->where('is_delete', false)
                ->whereNotIn('status', ['pending_payment', 'cancelled'])
                ->whereNotIn('id', Invoice::query()->whereNotNull('lessor_change_request_id')->select('lessor_change_request_id'))
                ->with('user')
                ->orderBy('id')
                ->each(fn (LessorChangeRequest $row) => $this->invoices->ensureLessorChangeInvoiceRecord($row));
        }
    }

    private function findOwnedContract(int $contractId): ?Contract
    {
        return Contract::query()
            ->where('user_id', auth()->id())
            ->visibleToOwner()
            ->with(['user', 'contractStatus', 'refundableContract'])
            ->find($contractId);
    }
}
