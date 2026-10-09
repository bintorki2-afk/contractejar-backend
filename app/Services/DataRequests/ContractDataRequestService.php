<?php

namespace App\Services\DataRequests;

use App\Models\Contract;
use App\Models\ContractDataRequest;
use App\Models\Employee;
use App\Models\EmployeeNotification;
use App\Services\CustomerNotificationService;
use App\Services\FirebaseNotificationService;
use App\Services\MessageTemplateService;
use App\Services\Orders\OrderFlowService;
use App\Services\Orders\OrderStageService;
use App\Support\SchemaCache;
use App\Support\SmartLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * طلب مرفق ناقص / تصحيح بيانات (دفعة هـ — E4): عملية متتبّعة من الموظف إلى العميل وبالعكس.
 *
 *  - الموظف يختار بنوداً من الكتالوج (config/data_requests.php) لقسم واحد → يُسجَّل الطلب، يُشعَر العميل
 *    (صندوق + Push «data_missing» برابط عميق ?fix=ID)، وتُجهَّز رسالة واتساب (قالب data_request).
 *  - العميل يفتح الرابط → يعدّل/يرفع الحقول المطلوبة عبر خطوات المعالج (الخطوة فقط) → يُحلّ الطلب تلقائياً
 *    عندما يتغيّر أي حقل من حقول البنود، ويُشعَر الموظف المستلم.
 *  - بلا رد 24 ساعة ⇒ يظهر في «عليك الحين» (awaiting_customer) + علامة customer_no_reply_24h؛
 *    72 ساعة ⇒ تنبيه تيليجرام للمالك مرة واحدة.
 */
class ContractDataRequestService
{
    public const DEEP_LINK_PARAM = 'fix';

    public function __construct(
        private readonly OrderFlowService $flow,
        private readonly MessageTemplateService $templates,
        private readonly CustomerNotificationService $customers,
        private readonly OrderStageService $stages,
    ) {}

    /**
     * الكتالوج كما تعرضه اللوحة.
     *
     * @return array{sections: list<array{key: string, label: string, items: list<array<string, mixed>>}>, reminder_after_hours: int, owner_alert_after_hours: int}
     */
    public function catalogue(): array
    {
        $sections = [];
        foreach ((array) config('data_requests.sections', []) as $key => $section) {
            $sections[] = [
                'key' => (string) $key,
                'label' => (string) ($section['label'] ?? $key),
                'items' => array_values(array_map(static fn (array $i) => [
                    'key' => (string) $i['key'],
                    'label' => (string) $i['label'],
                    'step' => (int) ($i['step'] ?? 1),
                    'fields' => array_values((array) ($i['fields'] ?? [])),
                ], (array) ($section['items'] ?? []))),
            ];
        }

        return [
            'sections' => $sections,
            'reminder_after_hours' => (int) config('data_requests.reminder_after_hours', 24),
            'owner_alert_after_hours' => (int) config('data_requests.owner_alert_after_hours', 72),
        ];
    }

    /**
     * @param  list<string>  $itemKeys
     * @return array{request: array<string, mixed>, whatsapp_url: string|null, message: string, phone: string|null}
     *
     * @throws ValidationException
     */
    public function create(Contract $contract, string $section, array $itemKeys, ?string $note, ?Employee $employee): array
    {
        $sections = (array) config('data_requests.sections', []);
        if (! array_key_exists($section, $sections)) {
            throw ValidationException::withMessages(['section' => ['القسم غير معروف: lessor | property | tenant']]);
        }
        $catalogue = collect((array) ($sections[$section]['items'] ?? []))->keyBy('key');
        $items = [];
        foreach (array_values(array_unique(array_map('strval', $itemKeys))) as $key) {
            $def = $catalogue->get($key);
            if ($def === null) {
                throw ValidationException::withMessages(['items' => ['بند غير معروف في هذا القسم: '.$key]]);
            }
            $items[] = ['key' => $key, 'label' => (string) $def['label'], 'step' => (int) ($def['step'] ?? 1), 'fields' => array_values((array) ($def['fields'] ?? []))];
        }
        if ($items === [] && blank($note)) {
            throw ValidationException::withMessages(['items' => ['اختر بنداً واحداً على الأقل أو اكتب ملاحظة.']]);
        }

        // طلب معلّق سابق لنفس القسم يُستبدل.
        ContractDataRequest::query()->where('contract_id', $contract->id)->where('section', $section)
            ->where('status', ContractDataRequest::STATUS_PENDING)
            ->update(['status' => ContractDataRequest::STATUS_CANCELLED, 'resolved_at' => now(), 'resolved_by' => $employee ? (string) $employee->id : 'system']);

        $request = ContractDataRequest::query()->create([
            'contract_id' => $contract->id,
            'section' => $section,
            'items' => $items,
            'note' => filled($note) ? trim((string) $note) : null,
            'status' => ContractDataRequest::STATUS_PENDING,
            'requested_by' => $employee?->id,
            'requested_at' => now(),
        ]);

        $labels = $request->itemLabels();
        $this->flow->activity($contract, 'data_request_sent', $employee, null, [
            'request_id' => $request->id, 'section' => $section, 'items' => $labels, 'note' => $request->note,
        ], 'employee', 'طلب مرفق ناقص ('.$this->sectionLabel($section).'): '.implode('، ', $labels));

        $deepLink = $this->deepLink($contract, $request);
        try {
            $this->customers->dataRequested($contract, $request, $deepLink);
        } catch (\Throwable $e) {
            Log::warning('data request customer notification failed', ['request_id' => $request->id, 'error' => $e->getMessage()]);
        }

        $whatsapp = $this->whatsapp($contract, $request, 'data_request');
        $this->customers->logExternal($contract, 'data_request', 'whatsapp', 'واتساب: طلب مرفق ناقص', $whatsapp['message']);

        return [
            'request' => $this->toAdminArray($request->fresh(['requester'])),
            'whatsapp_url' => $whatsapp['url'],
            'message' => $whatsapp['message'],
            'phone' => $whatsapp['phone'],
        ];
    }

    /**
     * @return array{request: array<string, mixed>, whatsapp_url: string|null, message: string, phone: string|null}
     */
    public function remind(Contract $contract, ContractDataRequest $request, ?Employee $employee): array
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['request' => ['الطلب ليس معلّقاً.']]);
        }
        $request->forceFill(['reminded_at' => now()])->save();
        $whatsapp = $this->whatsapp($contract, $request, 'data_request_reminder');
        $this->customers->logExternal($contract, 'data_request_reminder', 'whatsapp', 'واتساب: تذكير بطلب المرفق', $whatsapp['message']);
        $this->flow->activity($contract, 'data_request_reminded', $employee, null, ['request_id' => $request->id, 'items' => $request->itemLabels()], 'employee', 'تذكير العميل بطلب المرفق الناقص');

        try {
            $this->customers->dataRequested($contract, $request, $this->deepLink($contract, $request), true);
        } catch (\Throwable $e) {
            Log::warning('data request reminder notification failed', ['request_id' => $request->id, 'error' => $e->getMessage()]);
        }

        return [
            'request' => $this->toAdminArray($request->fresh(['requester'])),
            'whatsapp_url' => $whatsapp['url'],
            'message' => $whatsapp['message'],
            'phone' => $whatsapp['phone'],
        ];
    }

    /** حلّ يدوي من الموظف. */
    public function resolve(Contract $contract, ContractDataRequest $request, ?Employee $employee, ?string $note = null): ContractDataRequest
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['request' => ['الطلب ليس معلّقاً.']]);
        }
        $request->forceFill([
            'status' => ContractDataRequest::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolved_by' => $employee ? (string) $employee->id : 'system',
        ])->save();
        $this->flow->activity($contract, 'data_request_resolved', $employee, null, ['request_id' => $request->id, 'items' => $request->itemLabels(), 'by' => 'employee'], 'employee', $note ?: 'تم حل طلب المرفق الناقص يدوياً');

        return $request->fresh();
    }

    public function cancel(Contract $contract, ContractDataRequest $request, ?Employee $employee): ContractDataRequest
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['request' => ['الطلب ليس معلّقاً.']]);
        }
        $request->forceFill([
            'status' => ContractDataRequest::STATUS_CANCELLED,
            'resolved_at' => now(),
            'resolved_by' => $employee ? (string) $employee->id : 'system',
        ])->save();
        $this->flow->activity($contract, 'data_request_cancelled', $employee, null, ['request_id' => $request->id, 'items' => $request->itemLabels()], 'employee', 'إلغاء طلب المرفق الناقص');

        return $request->fresh();
    }

    /**
     * الحل التلقائي: عند تغيّر أي حقل من حقول البنود المعلّقة من طرف العميل.
     *
     * @param  list<string>  $changedFields
     * @return list<ContractDataRequest> الطلبات التي حُلّت
     */
    public function autoResolve(Contract $contract, array $changedFields): array
    {
        if ($changedFields === [] || ! SchemaCache::hasTable('contract_data_requests')) {
            return [];
        }

        $resolved = [];
        foreach ($this->pending($contract) as $request) {
            $hit = array_values(array_intersect($request->resolvingFields(), $changedFields));
            if ($hit === []) {
                continue;
            }
            $request->forceFill([
                'status' => ContractDataRequest::STATUS_RESOLVED,
                'resolved_at' => now(),
                'resolved_by' => 'customer',
                'resolved_fields' => $hit,
            ])->save();
            $labels = $request->itemLabels();
            $this->flow->activity($contract, 'data_request_resolved', null, null, [
                'request_id' => $request->id, 'items' => $labels, 'fields' => $hit, 'by' => 'customer',
            ], 'customer', 'العميل أرسل: '.implode('، ', $labels));
            $this->notifyEmployeesResolved($contract, $request);
            $resolved[] = $request;
        }

        return $resolved;
    }

    /** هل يُسمح للعميل بتعديل هذه الخطوة رغم أن الطلب مدفوع (وجود طلب مرفق معلّق لها)؟ */
    public function customerMayEditStep(Contract $contract, int $step): bool
    {
        if (! SchemaCache::hasTable('contract_data_requests')) {
            return false;
        }

        foreach ($this->pending($contract) as $request) {
            if (in_array($step, $request->steps(), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, ContractDataRequest> */
    public function pending(Contract $contract): Collection
    {
        if (! SchemaCache::hasTable('contract_data_requests')) {
            return collect();
        }

        return ContractDataRequest::query()->where('contract_id', $contract->id)
            ->where('status', ContractDataRequest::STATUS_PENDING)->orderBy('id')->get();
    }

    /** @return Collection<int, ContractDataRequest> */
    public function all(Contract $contract): Collection
    {
        if (! SchemaCache::hasTable('contract_data_requests')) {
            return collect();
        }

        return ContractDataRequest::query()->with('requester:id,name')->where('contract_id', $contract->id)->orderBy('id')->get();
    }

    /** @return list<array<string, mixed>> */
    public function pendingForAdmin(Contract $contract): array
    {
        return $this->pending($contract)->map(fn (ContractDataRequest $r) => $this->toAdminArray($r))->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function pendingForCustomer(Contract $contract): array
    {
        return $this->pending($contract)->map(fn (ContractDataRequest $r) => [
            'id' => $r->id,
            'section' => $r->section,
            'section_label' => $this->sectionLabel($r->section),
            'items' => array_values(array_map(static fn ($i) => ['key' => $i['key'], 'label' => $i['label'], 'step' => (int) ($i['step'] ?? 1), 'fields' => array_values((array) ($i['fields'] ?? []))], $r->items ?? [])),
            'note' => $r->note,
            'requested_at' => $r->requested_at?->toIso8601String(),
            'step' => $r->step(),
            'steps' => $r->steps(),
            'deep_link' => $this->deepLink($contract, $r),
            'banner' => 'مطلوب منك: '.implode('، ', $r->itemLabels()).(filled($r->note) ? ' — '.$r->note : ''),
        ])->values()->all();
    }

    /**
     * ملخص للصف في قائمة الطلبات: {items[], hours, section, request_id} أو null.
     *
     * @return array<string, mixed>|null
     */
    public function pendingSummary(Contract $contract): ?array
    {
        $pending = $this->pending($contract);
        if ($pending->isEmpty()) {
            return null;
        }
        $oldest = $pending->sortBy('requested_at')->first();
        $items = $pending->flatMap(fn (ContractDataRequest $r) => $r->itemLabels())->unique()->values()->all();

        return [
            'request_id' => $oldest->id,
            'section' => $oldest->section,
            'items' => $items,
            'hours' => (int) Carbon::parse($oldest->requested_at ?? $oldest->created_at)->diffInHours(now()),
            'requested_at' => optional($oldest->requested_at ?? $oldest->created_at)?->toIso8601String(),
            'reminded_at' => $oldest->reminded_at?->toIso8601String(),
            'label' => 'بانتظار العميل · '.implode('، ', $items),
        ];
    }

    /** @return array<string, mixed> */
    public function toAdminArray(ContractDataRequest $r): array
    {
        $contract = $r->contract;
        $since = $r->requested_at ?? $r->created_at;

        return [
            'id' => $r->id,
            'contract_id' => $r->contract_id,
            'section' => $r->section,
            'section_label' => $this->sectionLabel($r->section),
            'items' => array_values(array_map(static fn ($i) => ['key' => $i['key'], 'label' => $i['label'], 'step' => (int) ($i['step'] ?? 1)], $r->items ?? [])),
            'note' => $r->note,
            'status' => $r->status,
            'status_label' => match ($r->status) {
                ContractDataRequest::STATUS_PENDING => 'بانتظار العميل',
                ContractDataRequest::STATUS_RESOLVED => 'تم الحل',
                default => 'ملغي',
            },
            'requested_by' => $r->requested_by,
            'requested_by_name' => $r->requester?->name,
            'requested_at' => $since?->toIso8601String(),
            'hours_waiting' => $r->isPending() && $since ? (int) Carbon::parse($since)->diffInHours(now()) : null,
            'reminded_at' => $r->reminded_at?->toIso8601String(),
            'owner_alerted_at' => $r->owner_alerted_at?->toIso8601String(),
            'resolved_at' => $r->resolved_at?->toIso8601String(),
            'resolved_by' => $r->resolved_by,
            'resolved_fields' => $r->resolved_fields,
            'step' => $r->step(),
            'deep_link' => $contract ? $this->deepLink($contract, $r) : null,
            'whatsapp_url' => $contract && $r->isPending() ? $this->whatsapp($contract, $r, $r->reminded_at ? 'data_request_reminder' : 'data_request')['url'] : null,
            'label' => 'بانتظار العميل · '.implode('، ', $r->itemLabels()),
        ];
    }

    public function deepLink(Contract $contract, ContractDataRequest $request): string
    {
        return SmartLink::for($contract).'?'.self::DEEP_LINK_PARAM.'='.$request->id.'&step='.$request->step();
    }

    public function sectionLabel(string $section): string
    {
        return (string) (config('data_requests.sections.'.$section.'.label') ?? $section);
    }

    /**
     * @return array{phone: string|null, message: string, url: string|null, template_key: string}
     */
    public function whatsapp(Contract $contract, ContractDataRequest $request, string $templateKey = 'data_request'): array
    {
        $items = implode("\n", array_map(static fn ($l) => '• '.$l, $request->itemLabels()));
        if (filled($request->note)) {
            $items .= ($items !== '' ? "\n" : '').'• '.$request->note;
        }
        $vars = $this->templates->varsFor($contract, ['items' => $items, 'link' => $this->deepLink($contract, $request)]);
        $rendered = $this->templates->render($templateKey, 'whatsapp', $vars, "طلبك رقم {order}: نحتاج منك\n{items}\n{link}");
        $message = (string) ($rendered['body'] ?? '');
        $phone = $this->stages->customerPhone($contract);

        return [
            'phone' => $phone,
            'message' => $message,
            'url' => $phone !== null ? 'https://wa.me/'.$phone.'?text='.rawurlencode($message) : null,
            'template_key' => $templateKey,
        ];
    }

    /**
     * «عليك الحين»: الطلبات التي لم يرد عليها العميل منذ 24 ساعة (الأقدم أولاً).
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    public function awaitingCustomer(int $limit = 50, ?int $minHours = null): array
    {
        if (! SchemaCache::hasTable('contract_data_requests')) {
            return ['count' => 0, 'items' => []];
        }
        $minHours ??= (int) config('data_requests.reminder_after_hours', 24);
        $threshold = now()->subHours($minHours);

        $rows = ContractDataRequest::query()->with(['contract.user', 'contract.receivedContract.employee', 'requester:id,name'])
            ->where('status', ContractDataRequest::STATUS_PENDING)
            ->where(fn ($q) => $q->where('requested_at', '<=', $threshold)->orWhere(fn ($w) => $w->whereNull('requested_at')->where('created_at', '<=', $threshold)))
            ->orderBy('requested_at')->orderBy('id')->get()
            ->filter(fn (ContractDataRequest $r) => $r->contract !== null && (int) $r->contract->is_delete !== 1);

        $items = $rows->take($limit)->map(function (ContractDataRequest $r) {
            $c = $r->contract;
            $since = $r->requested_at ?? $r->created_at;
            $hoursWaiting = $since ? (int) Carbon::parse($since)->diffInHours(now()) : 0;
            // B-8: علم 72 ساعة يظهر للعنصر حتى يميّز الموظف الحالات الأقدم (وفق قاعدة customer_no_reply_72h).
            $flags = ['customer_no_reply_24h'];
            if ($hoursWaiting >= (int) config('data_requests.owner_alert_after_hours', 72)) {
                $flags[] = 'customer_no_reply_72h';
            }

            return [
                'request_id' => $r->id,
                'id' => $c->id,
                'uuid' => (string) $c->uuid,
                'bucket' => 'awaiting_customer',
                'order' => ['id' => $c->id, 'uuid' => (string) $c->uuid, 'contract_type' => $c->contract_type],
                'customer_name' => $c->user?->name,
                'customer_mobile' => $c->user?->contact_mobile ?: $c->user?->mobile,
                'employee_id' => $c->receivedContract?->employee_id,
                'employee_name' => $c->receivedContract?->employee?->name,
                'section' => $r->section,
                'section_label' => $this->sectionLabel($r->section),
                'items' => $r->itemLabels(),
                'note' => $r->note,
                'since' => $since?->toIso8601String(),
                'hours_waiting' => $hoursWaiting,
                'reminded_at' => $r->reminded_at?->toIso8601String(),
                'whatsapp_url' => $this->whatsapp($c, $r, 'data_request_reminder')['url'],
                'deep_link' => $this->deepLink($c, $r),
                'delay_flags' => $flags,
                'owner_alerted_at' => $r->owner_alerted_at?->toIso8601String(),
                'is_delayed' => true,
            ];
        })->values()->all();

        return ['count' => $rows->count(), 'items' => $items];
    }

    /**
     * تنبيه المالك عبر تيليجرام بعد 72 ساعة بلا رد (مرة واحدة لكل طلب). يرجع عدد التنبيهات المرسلة
     * (وفي وضع المعاينة: عدد الطلبات المرشّحة).
     *
     * B-4: `owner_alerted_at` يُضبط فقط عند نجاح الإرسال فعلاً — إن كان تيليجرام غير مضبوط أو فشل الإرسال
     * تبقى القيمة فارغة فتُعاد المحاولة في التشغيل التالي، ويُسجَّل نشاط `delay_flagged` مرة واحدة فقط
     * لكل طلب مرفق (B-5: هذا هو النشاط الوحيد لحدث الـ72 ساعة؛ أمر flag-delays لا يكرّره).
     */
    public function alertOwnerForStale(bool $dryRun = false): int
    {
        if (! SchemaCache::hasTable('contract_data_requests')) {
            return 0;
        }
        $hours = (int) config('data_requests.owner_alert_after_hours', 72);
        $threshold = now()->subHours($hours);
        $rows = ContractDataRequest::query()->with('contract.user')
            ->where('status', ContractDataRequest::STATUS_PENDING)
            ->whereNull('owner_alerted_at')
            ->where(fn ($q) => $q->where('requested_at', '<=', $threshold)->orWhere(fn ($w) => $w->whereNull('requested_at')->where('created_at', '<=', $threshold)))
            ->orderBy('id')->get();

        $telegram = app(\App\Services\TelegramService::class);
        $sent = 0;
        foreach ($rows as $r) {
            $c = $r->contract;
            if ($c === null) {
                continue;
            }
            if ($dryRun) {
                $sent++;

                continue;
            }

            $text = "⚠️ عميل لم يرد منذ {$hours} ساعة\n"
                .'الطلب #'.$c->uuid.' — '.($c->user?->name ?: 'عميل').' ('.($c->user?->contact_mobile ?: $c->user?->mobile ?: '—').")\n"
                .'المطلوب: '.implode('، ', $r->itemLabels())."\n"
                .'افتح الطلب: '.rtrim((string) config('app.dashboard_url', 'https://dashboard.contractejar.com'), '/').'/home/orders/'.$c->id;

            $delivered = false;
            try {
                $delivered = $telegram->configured() && $telegram->send($text);
            } catch (\Throwable $e) {
                Log::warning('data request owner alert failed', ['request_id' => $r->id, 'error' => $e->getMessage()]);
            }

            if ($delivered) {
                $r->forceFill(['owner_alerted_at' => now()])->save();
                $sent++;
            } else {
                Log::warning('data request owner alert not delivered; will retry', [
                    'request_id' => $r->id,
                    'telegram_configured' => $telegram->configured(),
                ]);
            }

            if (! $this->delayActivityLogged($c, $r)) {
                $this->flow->activity(
                    $c,
                    'delay_flagged',
                    null,
                    null,
                    ['delay_flags' => ['customer_no_reply_72h'], 'request_id' => $r->id, 'owner_alerted' => $delivered],
                    'system',
                    'عميل لم يرد على طلب المرفق منذ '.$hours.' ساعة — '.($delivered ? 'تم تنبيه المالك' : 'تعذّر تنبيه المالك عبر تيليجرام (ستُعاد المحاولة)')
                );
            }
        }

        return $sent;
    }

    /** هل سُجّل نشاط الـ72 ساعة لهذا الطلب من قبل؟ (منع التكرار عند إعادة المحاولة). */
    private function delayActivityLogged(Contract $contract, ContractDataRequest $request): bool
    {
        if (! SchemaCache::hasTable('contract_activities')) {
            return false;
        }
        try {
            return \App\Models\ContractActivity::query()
                ->where('contract_id', $contract->id)
                ->where('action', 'delay_flagged')
                ->where('after->request_id', $request->id)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function notifyEmployeesResolved(Contract $contract, ContractDataRequest $request): void
    {
        $contract->loadMissing('receivedContract.employee');
        $employeeId = $contract->receivedContract?->employee_id ?: $request->requested_by;
        $title = 'العميل أرسل المطلوب';
        $body = 'الطلب #'.$contract->uuid.': أرسل العميل '.implode('، ', $request->itemLabels()).' — راجعها وأكمل التوثيق.';
        $data = ['type' => 'data_request_resolved', 'contract_id' => (string) $contract->id, 'contract_uuid' => (string) $contract->uuid, 'request_id' => (string) $request->id];

        try {
            if (SchemaCache::hasTable('employee_notifications')) {
                EmployeeNotification::query()->create([
                    'employee_id' => $employeeId ?: null,
                    'contract_id' => $contract->id,
                    'kind' => 'data_request_resolved',
                    'title' => $title,
                    'body' => $body,
                    'url' => '/home/orders/'.$contract->id,
                    'data' => $data,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('employee notification store failed', ['error' => $e->getMessage()]);
        }

        try {
            $firebase = app(FirebaseNotificationService::class);
            if ($employeeId) {
                $firebase->sendToEmployee((int) $employeeId, $title, $body, $data);
            }
            $firebase->sendToTopic((string) config('services.firebase.employees_topic', 'employees'), $title, $body, $data);
        } catch (\Throwable $e) {
            Log::warning('employee push failed', ['error' => $e->getMessage()]);
        }

        try {
            $this->customers->logExternalEmployee($contract, 'data_request_resolved', $title, $body);
        } catch (\Throwable) {
            // سجل فقط
        }
    }
}
