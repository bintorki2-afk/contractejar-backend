<?php

namespace App\Services\Orders;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\Employee;
use App\Modules\Contracts\Actions\UpdateAdminContractStatusAction;
use App\Services\CustomerNotificationService;
use App\Services\MessageTemplateService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * أزرار المراحل (دفعة د — ب14، دفعة هـ — E3): «استلمت» → «وثّقت».
 * كل مرحلة: تتحقق من المتطلبات (قاعدة الدفع قبل التوثيق) → تضبط الحالة → تسجّل النشاط → ترسل إشعار العميل
 * → ترجع رسالة واتساب جاهزة من قالب المرحلة (ب16) لتفتحها اللوحة في wa.me.
 * مرحلة «إرسال المسودة» أُلغيت نهائياً: `/stage/draft_sent` ⇒ 410.
 */
class OrderStageService
{
    public const STAGES = ['received', 'notarized'];

    public const NEXT = ['received' => 'notarized', 'notarized' => null];

    public const LABELS = ['received' => 'استلمت', 'notarized' => 'وثّقت'];

    public const REMOVED_STAGES = ['draft_sent'];

    public const REMOVED_MESSAGE = 'أُلغيت مرحلة «إرسال المسودة» — بعد الاستلام يُوثَّق العقد مباشرةً.';

    public function __construct(
        private readonly OrderFlowService $flow,
        private readonly UpdateAdminContractStatusAction $statusAction,
        private readonly MessageTemplateService $templates,
        private readonly CustomerNotificationService $customers,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function run(Request $request, Contract $contract, string $stage, Employee $employee): array
    {
        if (! in_array($stage, self::STAGES, true)) {
            throw ValidationException::withMessages(['stage' => ['المرحلة غير معروفة: received | notarized']]);
        }

        match ($stage) {
            'received' => $this->receive($contract, $employee),
            'notarized' => $this->notarize($request, $contract),
        };

        $contract->refresh();
        $this->flow->activity($contract, 'stage_'.$stage, $employee, null, ['contract_status_id' => $contract->contract_status_id]);

        $whatsapp = $this->whatsapp($contract, $stage);
        $this->customers->logExternal($contract, 'stage_'.$stage, 'whatsapp', 'واتساب: '.self::LABELS[$stage], $whatsapp['message']);

        $status = \App\Support\ContractFrontendStatus::for($contract);

        return [
            'stage' => $stage,
            'stage_label' => self::LABELS[$stage],
            'next_stage' => self::NEXT[$stage],
            'next_stage_label' => self::NEXT[$stage] ? self::LABELS[self::NEXT[$stage]] : null,
            'next_stage_required_fields' => $this->requiredFields(self::NEXT[$stage]),
            'contract' => [
                'id' => $contract->id,
                'uuid' => (string) $contract->uuid,
                'contract_status_id' => $contract->contract_status_id,
                'status' => $status['status'],
                'status_label' => $status['status_label'],
            ],
            'whatsapp' => $whatsapp,
            'notifications_sent' => $this->customers->sentForContract($contract),
            'payment_state' => app(\App\Services\Payments\ContractPaymentState::class)->state($contract),
            'pending_data_requests' => app(\App\Services\DataRequests\ContractDataRequestService::class)->pendingForAdmin($contract),
            'warnings' => $this->warnings($contract),
        ];
    }

    /**
     * الحقول المطلوبة لكل مرحلة (تطلبها اللوحة داخل الحوار).
     *
     * @return list<array<string, mixed>>
     */
    public function requiredFields(?string $stage): array
    {
        return match ($stage) {
            'notarized' => [
                ['name' => 'deed_type', 'type' => 'select', 'required' => true, 'label_ar' => 'نوع الصك / طريقة الإضافة', 'options' => [
                    ['value' => 'paper', 'label_ar' => 'ورقي'], ['value' => 'electronic', 'label_ar' => 'إلكتروني'], ['value' => 'other', 'label_ar' => 'أخرى'],
                ]],
                ['name' => 'deed_number', 'type' => 'string', 'required' => true, 'label_ar' => 'رقم الصك'],
            ],
            default => [],
        };
    }

    private function receive(Contract $contract, Employee $employee): void
    {
        $result = $this->flow->receive($contract, $employee);
        if (! $result['ok']) {
            // استلمه الموظف نفسه مسبقاً ⇒ نعيد الرسالة فقط (idempotent).
            $existing = $result['existing'] ?? null;
            if ($existing !== null && (int) $existing->employee_id === (int) $employee->id) {
                return;
            }
            throw ValidationException::withMessages(['stage' => [
                $existing !== null
                    ? 'الطلب مستلم مسبقاً من '.($existing->employee?->name ?? 'موظف آخر').'.'
                    : (string) ($result['message'] ?? 'لا يمكن استلام الطلب.'),
            ]]);
        }
    }

    /**
     * «وثّقت»: يشترط الدفع الكامل وبلا رسوم معلّقة (422 code=payment_required|charge_pending)؛
     * مدير النظام يتجاوز بـ force=1. طلب مرفق ناقص معلّق لا يمنع (تحذير فقط في الرد).
     */
    private function notarize(Request $request, Contract $contract): void
    {
        $statusId = ContractStatus::idFor(ContractStatus::KEY_EJAR_AUTHENTICATED);
        if ($statusId === null) {
            throw ValidationException::withMessages(['stage' => ['حالة المرحلة غير موجودة في الإعدادات.']]);
        }
        if ((int) $contract->contract_status_id === $statusId) {
            return;
        }

        $this->toStatus($request, $contract, ContractStatus::KEY_EJAR_AUTHENTICATED);
    }

    private function toStatus(Request $request, Contract $contract, string $key): void
    {
        $statusId = ContractStatus::idFor($key);
        if ($statusId === null) {
            throw ValidationException::withMessages(['stage' => ['حالة المرحلة غير موجودة في الإعدادات.']]);
        }

        if ((int) $contract->contract_status_id === $statusId) {
            return; // نفس المرحلة ⇒ نعيد الرسالة فقط
        }

        $request->merge(['contract_status_id' => $statusId, 'status_id' => $statusId]);
        $result = $this->statusAction->updateLive($request, $contract);
        if (! ($result['ok'] ?? false)) {
            $errors = $result['errors'] ?? ['stage' => [$result['message'] ?? 'تعذّر تحديث الحالة.']];
            throw ValidationException::withMessages(is_array($errors) ? $errors : $errors->toArray());
        }
    }

    /**
     * @return array{phone: string|null, message: string, url: string|null, template_key: string}
     */
    private function whatsapp(Contract $contract, string $stage): array
    {
        $key = 'stage_'.$stage;
        $vars = $this->templates->varsFor($contract);
        $rendered = $this->templates->render($key, 'whatsapp', $vars, 'طلبك رقم {order}: '.self::LABELS[$stage]);
        $message = (string) ($rendered['body'] ?? '');
        $phone = $this->customerPhone($contract);

        return [
            'phone' => $phone,
            'message' => $message,
            'url' => $phone !== null ? 'https://wa.me/'.$phone.'?text='.rawurlencode($message) : null,
            'template_key' => $key,
        ];
    }

    /**
     * تحذيرات (لا تمنع): طلب مرفق ناقص ما زال معلّقاً.
     *
     * @return list<array{code: string, message: string}>
     */
    public function warnings(Contract $contract): array
    {
        $warnings = [];
        try {
            $pending = app(\App\Services\DataRequests\ContractDataRequestService::class)->pendingForAdmin($contract);
            if ($pending !== []) {
                $labels = collect($pending)->flatMap(fn ($r) => $r['items'])->pluck('label')->unique()->implode('، ');
                $warnings[] = ['code' => 'data_request_pending', 'message' => 'لا يزال هناك طلب مرفق ناقص بانتظار العميل: '.$labels];
            }
        } catch (\Throwable) {
            // بلا تحذير
        }

        return $warnings;
    }

    /** جوال العميل للتواصل بصيغة دولية (9665XXXXXXXX). */
    public function customerPhone(Contract $contract): ?string
    {
        $contract->loadMissing('user');
        $candidates = [];
        if ($contract->draft_contact_number_mode === 'another' && filled($contract->draft_contact_number)) {
            $candidates[] = $contract->draft_contact_number;
        }
        array_push($candidates, $contract->user?->contact_mobile, $contract->user?->mobile, $contract->tenant_mobile);

        foreach ($candidates as $raw) {
            $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';
            if ($digits === '') {
                continue;
            }
            if (str_starts_with($digits, '00')) {
                $digits = substr($digits, 2);
            }
            if (str_starts_with($digits, '0')) {
                $digits = '966'.substr($digits, 1);
            } elseif (strlen($digits) === 9 && str_starts_with($digits, '5')) {
                $digits = '966'.$digits;
            }
            if (preg_match('/^9665\d{8}$/', $digits) === 1) {
                return $digits;
            }
        }

        return null;
    }
}
