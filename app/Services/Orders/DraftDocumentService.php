<?php

namespace App\Services\Orders;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\Setting;
use App\Services\CustomerNotificationService;
use App\Support\CustomerLinks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * دفعة (و) — D9: «الدفع بعد مشاهدة المسودة» (خيار يفعّله المالك من اللوحة).
 *  - ليس إعادة لمرحلة «إرسال المسودة» الملغاة (E3): لا حالة ولا خطوة رحلة — مرفق + سجل + إشعار + زر دفع.
 *  - الموظف يرفع ملف المسودة (PDF/صورة) على الطلب ⇒ يُخزّن على القرص الخاص (Volume) ويُقرأ برابط موقّع فقط.
 */
class DraftDocumentService
{
    public const DISK = 'local';

    public const DIR = 'contracts/draft-documents';

    public const MAX_KB = 10240;

    public const MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly OrderFlowService $flow,
        private readonly CustomerNotificationService $customers,
    ) {}

    /** @throws ValidationException */
    public function upload(Contract $contract, UploadedFile $file, ?Employee $employee, ?string $note = null): Contract
    {
        if ((int) $contract->is_delete === 1) {
            throw ValidationException::withMessages(['file' => ['الطلب محذوف.']]);
        }
        $path = $file->store(self::DIR.'/'.$contract->id, self::DISK);
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['file' => ['تعذّر حفظ ملف المسودة.']]);
        }
        $old = $contract->draft_document_path;

        $contract->forceFill([
            'draft_document_path' => $path,
            'draft_document_name' => mb_substr((string) $file->getClientOriginalName(), 0, 250) ?: basename($path),
            'draft_document_mime' => (string) ($file->getMimeType() ?: $file->getClientMimeType()),
            'draft_document_note' => filled($note) ? mb_substr(trim((string) $note), 0, 500) : null,
            'draft_document_uploaded_at' => now(),
            'draft_document_uploaded_by' => $employee?->id,
        ])->save();

        if (filled($old) && $old !== $path) {
            try {
                Storage::disk(self::DISK)->delete($old);
            } catch (\Throwable) {
            }
        }

        $this->flow->activity($contract, 'draft_document_uploaded', $employee, null, [
            'name' => $contract->draft_document_name,
            'replaced' => filled($old),
        ], 'employee', $contract->draft_document_note);

        try {
            $this->customers->draftReady($contract);
        } catch (\Throwable $e) {
            report($e);
        }

        return $contract;
    }

    public function remove(Contract $contract, ?Employee $employee): Contract
    {
        $old = $contract->draft_document_path;
        if (! filled($old)) {
            return $contract;
        }
        $contract->forceFill([
            'draft_document_path' => null,
            'draft_document_name' => null,
            'draft_document_mime' => null,
            'draft_document_note' => null,
            'draft_document_uploaded_at' => null,
            'draft_document_uploaded_by' => null,
        ])->save();
        try {
            Storage::disk(self::DISK)->delete($old);
        } catch (\Throwable) {
        }
        $this->flow->activity($contract, 'draft_document_removed', $employee, null, null, 'employee');

        return $contract;
    }

    /**
     * العميل اختار «إرسال الطلب والدفع بعد مشاهدة المسودة».
     *
     * @throws ValidationException
     */
    public function requestPayAfterDraft(Contract $contract): Contract
    {
        if (! Setting::payAfterDraftEnabled()) {
            throw ValidationException::withMessages(['pay_after_draft_disabled' => ['هذا الخيار غير متاح حالياً.']]);
        }
        if ((int) $contract->step < \App\Support\ContractSubmission::SUBMITTED_STEP) {
            throw ValidationException::withMessages(['not_submitted' => ['أكمل بيانات الطلب أولاً ثم أرسله.']]);
        }
        $state = app(\App\Services\Payments\ContractPaymentState::class)->state($contract);
        if ((bool) ($state['is_paid'] ?? false) || (float) ($state['paid_total'] ?? 0) > 0.009) {
            throw ValidationException::withMessages(['already_paid' => ['هذا الطلب مدفوع مسبقاً.']]);
        }
        if (! (bool) $contract->pay_after_draft) {
            $contract->forceFill(['pay_after_draft' => true, 'pay_after_draft_requested_at' => now()])->save();
            $this->flow->activity($contract, 'pay_after_draft_requested', null, null, ['pay_after_draft' => true], 'customer');
        }

        return $contract;
    }

    public static function fileUrl(Contract $contract): ?string
    {
        if (! filled($contract->draft_document_path ?? null)) {
            return null;
        }
        try {
            return CustomerLinks::temporarySignedRoute('v2.contracts.draft-document', now()->addDays(7), ['contract' => $contract->getKey()]);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    public static function documentArray(Contract $contract, bool $staff = false): ?array
    {
        if (! filled($contract->draft_document_path ?? null)) {
            return null;
        }
        $mime = (string) ($contract->draft_document_mime ?? '');
        $doc = [
            'url' => self::fileUrl($contract),
            'name' => $contract->draft_document_name,
            'mime' => $mime !== '' ? $mime : null,
            'is_pdf' => str_contains($mime, 'pdf') || str_ends_with(strtolower((string) $contract->draft_document_name), '.pdf'),
            'uploaded_at' => $contract->draft_document_uploaded_at ? \Illuminate\Support\Carbon::parse($contract->draft_document_uploaded_at)->toIso8601String() : null,
            'note' => $contract->draft_document_note,
        ];
        if ($staff) {
            $by = $contract->draft_document_uploaded_by ? Employee::query()->find($contract->draft_document_uploaded_by) : null;
            $doc['uploaded_by'] = $by ? ['id' => $by->id, 'name' => $by->name] : null;
        }

        return $doc;
    }

    /** @return array{draft_document: array<string, mixed>|null, pay_after_draft: bool, pay_after_draft_requested_at: string|null} */
    public static function customerFields(Contract $contract, bool $staff = false): array
    {
        $at = $contract->pay_after_draft_requested_at ?? null;

        return [
            'draft_document' => self::documentArray($contract, $staff),
            'pay_after_draft' => (bool) ($contract->pay_after_draft ?? false),
            'pay_after_draft_requested_at' => $at ? \Illuminate\Support\Carbon::parse($at)->toIso8601String() : null,
        ];
    }

    /** @return array{0: string, 1: string}|null */
    public static function resolveFile(Contract $contract): ?array
    {
        $path = ltrim(trim((string) ($contract->draft_document_path ?? '')), '/');
        if ($path === '' || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return [self::DISK, $path];
    }
}
