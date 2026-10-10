<?php

namespace App\Modules\Contracts\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Traits\Responser;
use App\Models\Contract;
use App\Services\Orders\DraftDocumentService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * دفعة (و) — D9: «الدفع بعد مشاهدة المسودة» — جانب العميل.
 */
class DraftDocumentController extends Controller
{
    use Responser;

    /** POST /api/v2/contract/{uuid}/pay-after-draft */
    public function payAfterDraft(string $uuid, DraftDocumentService $drafts)
    {
        $contract = Contract::query()->ownedBy(Contract::requireApiUserId())
            ->where(fn ($q) => $q->where('uuid', $uuid)->when(ctype_digit($uuid), fn ($w) => $w->orWhere('id', (int) $uuid)))
            ->first();
        if ($contract === null) {
            return $this->errorMessage(trans('api.contract_not_found'), 404);
        }

        try {
            $contract = $drafts->requestPayAfterDraft($contract);
        } catch (ValidationException $e) {
            $code = (string) array_key_first($e->errors());

            return $this->jsonResponse([
                'success' => false,
                'code' => $code,
                'message' => collect($e->errors())->flatten()->first(),
                'data' => null,
            ], 422);
        }

        return $this->apiResponse(array_merge([
            'uuid' => (string) $contract->uuid,
            'message' => 'تم إرسال طلبك — سيرسل لك فريقنا مسودة العقد لمراجعتها ثم تدفع للتوثيق.',
        ], DraftDocumentService::customerFields($contract)), trans('api.success'));
    }

    /** GET /api/v2/contracts/{contract}/draft-document (رابط موقّع مؤقت). */
    public function file(Contract $contract)
    {
        $resolved = DraftDocumentService::resolveFile($contract);
        abort_if($resolved === null, 404);
        [$disk, $path] = $resolved;

        return Storage::disk($disk)->response($path, $contract->draft_document_name ?: basename($path), [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
