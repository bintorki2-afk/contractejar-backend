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
 * أزرار المراحل (دفعة د — ب14): «استلمت» → «أرسلت المسودة» → «وثّقت».
 * كل مرحلة: تتحقق من المتطلبات (قاعدة المسودة أولاً) → تضبط الحالة → تسجّل النشاط → ترسل إشعار العميل
 * → ترجع رسالة واتساب جاهزة من قالب المرحلة (ب16) لتفتحها اللوحة في wa.me.
 */
class OrderStageService
{
    public const STAGES = ['received', 'draft_sent', 'notarized'];

    public const NEXT = ['received' => 'draft_sent', 'draft_sent' => 'notarized', 'notarized' => null];

    public const LABELS = ['received' => 'استلمت', 'draft_sent' => 'أرسلت المسودة', 'notarized' => 'وثّقت'];

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
            throw ValidationException::withMessages(['stage' => ['المرحلة غير معروفة: received | draft_sent | notarized']]);
        }

        match ($stage) {
            'received' => $this->receive($contract, $employee),
            'draft_sent' => $this->toStatus($request, $contract, ContractStatus::KEY_WHATSAPP_DRAFT),
            'notarized' => $this->toStatus($request, $contract, ContractStatus::KEY_EJAR_AUTHENTICATED),
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
            'draft_sent' => [
                ['name' => 'ejar_contract_draft_number', 'type' => 'string', 'required' => true, 'label_ar' => 'رقم مسودة عقد إيجار'],
                ['name' => 'contact_number_mode', 'type' => 'select', 'required' => true, 'label_ar' => 'رقم التواصل', 'options' => [
                    ['value' => 'same', 'label_ar' => 'نفس الرقم'], ['value' => 'another', 'label_ar' => 'رقم آخر'],
                ]],
                ['name' => 'contact_number', 'type' => 'string', 'required' => false, 'required_if' => ['contact_number_mode', 'another'], 'label_ar' => 'رقم التواصل الجديد'],
            ],
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

    private function toStatus(Request $request, Contract $contract, string $key): void
    {
        $statusId = ContractStatus::idFor($key);
        if ($statusId === null) {
            throw ValidationException::withMessages(['stage' => ['حالة المرحلة غير موجودة في الإعدادات.']]);
        }

        if ($key === ContractStatus::KEY_WHATSAPP_DRAFT && ! $contract->receivedContract()->exists()) {
            throw ValidationException::withMessages(['stage' => ['استلم الطلب أولاً ثم أرسل المسودة.']]);
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
