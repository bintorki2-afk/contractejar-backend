<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\LessorChangeRequest;
use App\Models\NotificationDispatch;
use App\Models\Offer;
use App\Models\User;
use App\Support\ContractFrontendStatus;
use App\Support\ContractJourney;
use App\Support\SmartLink;
use Illuminate\Database\QueryException;
use App\Services\MessageTemplateService;
use Illuminate\Support\Facades\Log;

/**
 * إشعارات العملاء (ف8): تخزين في صندوق الإشعارات (offers) + Push (FCM) + سجل إرسال يمنع التكرار.
 *
 * لا يرمي استثناءً للمستدعي أبداً — أي فشل يُسجَّل في اللوق فقط.
 */
class CustomerNotificationService
{
    public const KIND_DRAFT_SENT = 'draft_sent';

    public const KIND_NOTARIZED = 'notarized';

    public const KIND_PAYMENT_SUCCESS = 'payment_success';

    public const KIND_STATUS_CHANGED = 'status_changed';

    public const KIND_LESSOR_CHANGE_STATUS = 'lessor_change_status';

    public const KIND_ORDER_ABANDONED_24H = 'order_abandoned_24h';

    public const KIND_ORDER_ABANDONED_3D = 'order_abandoned_3d';

    public const KIND_AWAITING_PAYMENT_2H = 'awaiting_payment_2h';

    public const KIND_RENEWAL_60D = 'renewal_60d';

    public const KIND_RENEWAL_30D = 'renewal_30d';

    public const KIND_OFFER = 'offer';

    public const KIND_ANNOUNCEMENT = 'announcement';

    // دفعة (د) — ب10: مصفوفة الإشعارات لكل إجراء من اللوحة.
    public const KIND_ASSIGNED = 'assigned';

    public const KIND_DATA_MISSING = 'data_missing';

    public const KIND_REFUND = 'refund';

    public const KIND_DISCOUNT_APPLIED = 'discount_applied';

    public const KIND_DELAY_ALERT = 'delay_alert';

    /** شرائح الإرسال الجماعي. */
    public const SEGMENTS = ['all', 'has_active_contract', 'city'];

    /** نص إشعار «تحديث الحالة» لكل مفتاح حالة (يُستبدل {order}). */
    public const STATUS_BODIES = [
        'under_review' => 'تم استلام دفعتك — طلبك رقم {order} قيد المراجعة الآن',
        'received' => 'استلم فريقنا طلبك رقم {order} وبدأ العمل عليه',
        'received_by_employee' => 'استلم موظفنا طلبك رقم {order} وبدأ العمل عليه',
        'on_hold' => 'طلبك رقم {order} معلق — نحتاج استكمال بعض البيانات، تواصل معنا',
        'cancelled' => 'تم إلغاء طلبك رقم {order} — للاستفسار تواصل معنا',
        'refunded' => 'تم استرجاع مبلغ طلبك رقم {order}',
        'waiting_supervisor' => 'طلبك رقم {order} بانتظار اعتماد المشرف',
        'completed' => 'اكتمل طلبك رقم {order} — شكراً لثقتك',
        'new' => 'تم تسجيل طلبك رقم {order}',
        'paid' => 'تم استلام دفعتك لطلب {order}',
    ];

    /** الأنواع المعروفة (للفلترة في اللوحة). */
    public const KINDS = [
        self::KIND_DRAFT_SENT,
        self::KIND_NOTARIZED,
        self::KIND_PAYMENT_SUCCESS,
        self::KIND_STATUS_CHANGED,
        self::KIND_LESSOR_CHANGE_STATUS,
        self::KIND_ORDER_ABANDONED_24H,
        self::KIND_ORDER_ABANDONED_3D,
        self::KIND_AWAITING_PAYMENT_2H,
        self::KIND_RENEWAL_60D,
        self::KIND_RENEWAL_30D,
        self::KIND_OFFER,
        self::KIND_ANNOUNCEMENT,
        self::KIND_ASSIGNED,
        self::KIND_DATA_MISSING,
        self::KIND_REFUND,
        self::KIND_DISCOUNT_APPLIED,
    ];

    public const KIND_LABELS = [
        self::KIND_ASSIGNED => 'إسناد الطلب',
        self::KIND_DATA_MISSING => 'بيانات ناقصة',
        self::KIND_REFUND => 'استرجاع المبلغ',
        self::KIND_DISCOUNT_APPLIED => 'تطبيق خصم',
        self::KIND_DELAY_ALERT => 'تنبيه تأخير (للموظفين)',
        self::KIND_DRAFT_SENT => 'إرسال المسودة',
        self::KIND_NOTARIZED => 'تم التوثيق',
        self::KIND_PAYMENT_SUCCESS => 'استلام الدفعة',
        self::KIND_STATUS_CHANGED => 'تحديث الحالة',
        self::KIND_LESSOR_CHANGE_STATUS => 'طلب تغيير المؤجر',
        self::KIND_ORDER_ABANDONED_24H => 'طلب غير مكتمل (24 ساعة)',
        self::KIND_ORDER_ABANDONED_3D => 'طلب غير مكتمل (3 أيام)',
        self::KIND_AWAITING_PAYMENT_2H => 'بانتظار الدفع (ساعتان)',
        self::KIND_RENEWAL_60D => 'تجديد العقد (60 يوم)',
        self::KIND_RENEWAL_30D => 'تجديد العقد (30 يوم)',
        self::KIND_OFFER => 'عرض',
        self::KIND_ANNOUNCEMENT => 'إعلان',
    ];

    public function __construct(private readonly FirebaseNotificationService $firebase) {}

    /**
     * يخزّن الإشعار في صندوق العميل، يرسل Push إن وُجد توكن، ويسجّل الإرسال.
     *
     * @param  array<string, mixed>  $data  بيانات إضافية تصل مع الـ push (مثل ask_rating) — تُحفظ أيضاً مع الإشعار.
     * @param  bool  $dedupe  منع التكرار لنفس النوع ونفس الطلب (يتجاهل عند عدم وجود طلب).
     * @param  string|null  $dedupeQualifier  مؤهل للأنواع المتكررة (مثل مفتاح الحالة).
     * @return Offer|null  الإشعار المخزَّن، أو null إذا تخطّيناه (مكرر) أو فشل التخزين.
     */
    public function notify(
        User $user,
        string $kind,
        string $title,
        string $body,
        array $data = [],
        ?Contract $contract = null,
        ?LessorChangeRequest $lessorChange = null,
        bool $dedupe = true,
        ?string $dedupeQualifier = null,
    ): ?Offer {
        try {
            $url = (string) ($data['url'] ?? $this->urlFor($contract, $lessorChange) ?? '');
            unset($data['url']);

            $dispatch = $this->claimDispatch(
                $kind,
                (int) $user->id,
                $contract?->id,
                $lessorChange?->id,
                $dedupe ? NotificationDispatch::dedupeKey($kind, $contract?->id, $lessorChange?->id, $dedupeQualifier) : null,
                $title,
                $body,
                $url !== '' ? $url : null,
            );

            if ($dispatch === null) {
                return null; // مكرر (أو تعذّر الحجز)
            }

            $offer = $this->storeOffer($user, $kind, $title, $body, $url, $data, $contract, $lessorChange);

            $payload = array_merge($this->basePayload($kind, $url, $contract, $lessorChange), $data, [
                'notification_id' => (string) ($offer?->id ?? ''),
            ]);

            $result = $this->push($user, $title, $body, $payload);

            $dispatch->forceFill(['push_result' => $result, 'sent_at' => now()])->save();

            return $offer;
        } catch (\Throwable $e) {
            Log::warning('Customer notification failed', [
                'kind' => $kind,
                'user_id' => $user->id,
                'contract_id' => $contract?->id,
                'lessor_change_request_id' => $lessorChange?->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * إرسال لكل العملاء النشطين (عرض/إعلان من اللوحة): صف إشعار لكل عميل + Push عبر الموضوع والتوكنات.
     *
     * @param  array<string, mixed>  $data
     * @return array{recipients: int, push: array<string, mixed>}
     */
    public function broadcast(string $kind, string $title, string $body, ?string $url = null, array $data = [], array $options = []): array
    {
        $recipients = 0;
        $pushResult = ['sent' => 0, 'failed' => 0, 'topic_sent' => false, 'missing_token' => false];
        $segment = (string) ($options['segment'] ?? 'all');

        // ب10: كوبون اختياري مع صلاحيته — يصل مع الإشعار ليعرضه التطبيق/الموقع مع زر نسخ.
        if (filled($options['coupon_code'] ?? null)) {
            $data['coupon_code'] = (string) $options['coupon_code'];
        }
        if (filled($options['valid_until'] ?? null)) {
            $data['valid_until'] = (string) $options['valid_until'];
        }
        if ($segment !== 'all') {
            $data['segment'] = $segment;
        }

        $userIds = [];
        try {
            $now = now();
            $this->segmentQuery($options)
                ->select('id')
                ->orderBy('id')
                ->chunkById(500, function ($users) use (&$recipients, $kind, $title, $body, $url, $data, $now) {
                    $rows = [];
                    foreach ($users as $user) {
                        $rows[] = [
                            'user_id' => $user->id,
                            'title' => $title,
                            'body' => $body,
                            'kind' => $kind,
                            'url' => $url,
                            'data' => $data !== [] ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
                            'is_active' => true,
                            'is_read' => false,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($rows !== []) {
                        Offer::query()->insert($rows);
                        $recipients += count($rows);
                    }
                });
            if ($segment !== 'all') {
                $userIds = $this->segmentQuery($options)->pluck('id')->all();
            }
        } catch (\Throwable $e) {
            Log::warning('Broadcast notification storage failed', ['kind' => $kind, 'error' => $e->getMessage()]);
        }

        try {
            $payload = array_merge(['kind' => $kind, 'url' => (string) ($url ?? '')], $data);
            if ($segment === 'all') {
                $pushResult = $this->firebase->sendToAllUsers($title, $body, $payload);
            } else {
                foreach ($userIds as $uid) {
                    $r = $this->firebase->sendToUser((int) $uid, $title, $body, $payload);
                    $pushResult['sent'] += (int) ($r['sent'] ?? 0);
                    $pushResult['failed'] += (int) ($r['failed'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Broadcast push failed', ['kind' => $kind, 'error' => $e->getMessage()]);
        }

        try {
            NotificationDispatch::query()->create([
                'user_id' => null,
                'kind' => $kind,
                'dedupe_key' => null,
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'push_result' => ($pushResult['sent'] ?? 0) > 0 || ! empty($pushResult['topic_sent']) ? 'sent' : 'no_token',
                'recipients_count' => $recipients,
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Broadcast dispatch log failed', ['kind' => $kind, 'error' => $e->getMessage()]);
        }

        return ['recipients' => $recipients, 'push' => $pushResult, 'segment' => $segment];
    }

    // ───────────────────────── الأحداث (فورية) ─────────────────────────

    /**
     * تغيّرت حالة الطلب من اللوحة: مسودة واتساب / توثيق / تحديث عام.
     */
    public function contractStatusChanged(Contract $contract): ?Offer
    {
        $user = $this->ownerOf($contract);
        if ($user === null) {
            return null;
        }

        $payload = ContractFrontendStatus::for($contract);
        $key = (string) ($payload['status'] ?? 'unknown');
        $label = (string) ($payload['status_label'] ?? '');
        $order = $this->orderNumber($contract);
        $firebase = ContractFrontendStatus::firebaseData($contract);

        if ($key === 'whatsapp_draft' && ! (bool) $contract->is_draft) {
            return $this->notify(
                $user,
                self::KIND_DRAFT_SENT,
                'وصلتك مسودة العقد',
                'أرسلنا لك مسودة العقد عبر واتساب — اطّلع عليها وأكّد لنا لنوثّقه',
                $firebase,
                contract: $contract,
            );
        }

        if (in_array($key, ContractJourney::NOTARIZED_KEYS, true) && ! (bool) $contract->is_draft) {
            return $this->notify(
                $user,
                self::KIND_NOTARIZED,
                '🎉 تم توثيق عقدك',
                '🎉 تم توثيق عقدك في إيجار — نسعد بتقييمك للخدمة',
                array_merge($firebase, ['ask_rating' => true]),
                contract: $contract,
            );
        }

        $latestHistoryId = (int) ($contract->statusHistories()->max('id') ?? 0);
        $body = isset(self::STATUS_BODIES[$key])
            ? str_replace('{order}', $order, self::STATUS_BODIES[$key])
            : "طلبك رقم {$order}: ".($label !== '' ? $label : 'تم تحديث حالة طلبك');
        // ب16: قالب Push من اللوحة (status_<key>) إن وُجد يتقدّم على النص الافتراضي.
        $title = 'تحديث حالة طلبك';
        try {
            $templates = app(MessageTemplateService::class);
            $rendered = $templates->render('status_'.$key, 'push', $templates->varsFor($contract), $body, $title);
            if ($rendered !== null) {
                $body = $rendered['body'];
                $title = $rendered['title'] ?? $title;
            }
        } catch (\Throwable) {
            // النص الافتراضي
        }

        return $this->notify(
            $user,
            self::KIND_STATUS_CHANGED,
            $title,
            $body,
            $firebase,
            contract: $contract,
            dedupeQualifier: $key.'#'.$latestHistoryId,
        );
    }

    public function paymentSucceeded(Contract $contract): ?Offer
    {
        $user = $this->ownerOf($contract);
        if ($user === null) {
            return null;
        }

        $order = $this->orderNumber($contract);

        return $this->notify(
            $user,
            self::KIND_PAYMENT_SUCCESS,
            'تم استلام دفعتك',
            "تم استلام دفعتك لطلب {$order} — فريقنا يبدأ المراجعة الآن",
            ['type' => 'payment_success'],
            contract: $contract,
        );
    }

    public function lessorChangeStatusChanged(LessorChangeRequest $request): ?Offer
    {
        $request->loadMissing('user');
        $user = $request->user;
        if (! $user instanceof User) {
            return null;
        }

        return $this->notify(
            $user,
            self::KIND_LESSOR_CHANGE_STATUS,
            'تحديث على طلب تغيير المؤجر',
            "تحديث على طلب تغيير المؤجر رقم {$request->uuid}: {$request->statusLabel()}",
            ['type' => 'lessor_change_status', 'status' => (string) $request->status],
            lessorChange: $request,
            dedupeQualifier: (string) $request->status,
        );
    }

    /** أُسند الطلب لموظف (تلقائياً أو يدوياً). */
    public function assigned(Contract $contract, ?string $employeeName = null): ?Offer
    {
        $user = $this->ownerOf($contract);
        if ($user === null) {
            return null;
        }
        $order = $this->orderNumber($contract);

        return $this->notify(
            $user,
            self::KIND_ASSIGNED,
            'بدأنا العمل على طلبك',
            "استلم موظفنا طلبك رقم {$order} وسيتواصل معك قريباً",
            array_merge(ContractFrontendStatus::firebaseData($contract), ['type' => 'assigned']),
            contract: $contract,
        );
    }

    /** بيانات ناقصة — رابط عميق للخطوة المطلوبة. */
    public function dataMissing(Contract $contract, ?string $message = null, ?int $step = null): ?Offer
    {
        $user = $this->ownerOf($contract);
        if ($user === null) {
            return null;
        }
        $order = $this->orderNumber($contract);
        $body = filled($message)
            ? "طلبك رقم {$order}: ".trim((string) $message)
            : "طلبك رقم {$order} يحتاج استكمال بعض البيانات — افتح الطلب وأكمل المطلوب";

        return $this->notify(
            $user,
            self::KIND_DATA_MISSING,
            'نحتاج بيانات إضافية لطلبك',
            $body,
            ['type' => 'data_missing', 'step' => $step, 'deep_link' => SmartLink::for($contract).($step ? '?step='.$step : '')],
            contract: $contract,
            dedupe: false,
        );
    }

    /** استرجاع المبلغ (كلي/جزئي). */
    public function refunded(Contract $contract, float $amount, bool $full): ?Offer
    {
        $user = $this->ownerOf($contract);
        if ($user === null) {
            return null;
        }
        $order = $this->orderNumber($contract);
        $amountLabel = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');

        return $this->notify(
            $user,
            self::KIND_REFUND,
            'تم استرجاع المبلغ',
            $full
                ? "تم استرجاع مبلغ طلبك رقم {$order} كاملاً ({$amountLabel} ر.س) — يصل لحسابك خلال 3–14 يوم عمل حسب البنك"
                : "تم استرجاع {$amountLabel} ر.س من طلبك رقم {$order} — يصل لحسابك خلال 3–14 يوم عمل حسب البنك",
            ['type' => 'refund', 'amount' => $amount, 'full' => $full],
            contract: $contract,
            dedupe: false,
        );
    }

    /** تطبيق خصم/كوبون على الطلب من اللوحة. */
    public function discountApplied(Contract $contract, float $amount, ?string $code = null, ?float $totalAfter = null): ?Offer
    {
        $user = $this->ownerOf($contract);
        if ($user === null) {
            return null;
        }
        $order = $this->orderNumber($contract);
        $amountLabel = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');

        return $this->notify(
            $user,
            self::KIND_DISCOUNT_APPLIED,
            'تم تطبيق خصم على طلبك',
            "طبّقنا خصماً بقيمة {$amountLabel} ر.س على طلبك رقم {$order} — أكمل الدفع الآن",
            array_filter(['type' => 'discount_applied', 'amount' => $amount, 'coupon_code' => $code, 'total_after' => $totalAfter], static fn ($v) => $v !== null),
            contract: $contract,
            dedupe: false,
        );
    }

    /**
     * الإشعارات المرسلة لطلب (للّوحة): نوع، قناة، وقت الإرسال، نتيجة الـ push.
     *
     * @return list<array<string, mixed>>
     */
    public function sentForContract(Contract $contract): array
    {
        try {
            return NotificationDispatch::query()
                ->where('contract_id', $contract->id)
                ->orderBy('id')
                ->get()
                ->map(fn (NotificationDispatch $d) => [
                    'id' => $d->id,
                    'kind' => $d->kind,
                    'kind_label' => self::KIND_LABELS[$d->kind] ?? $d->kind,
                    'channel' => $d->channel ?? 'push',
                    'channels' => ($d->channel ?? 'push') === 'push'
                        ? array_values(array_filter(['inbox', $d->push_result === 'sent' ? 'push' : null]))
                        : [$d->channel],
                    'title' => $d->title,
                    'body' => $d->body,
                    'push_result' => $d->push_result,
                    'sent_at' => optional($d->sent_at ?? $d->created_at)?->toIso8601String(),
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * تسجيل رسالة أُرسلت خارج التطبيق (واتساب/SMS من اللوحة) في سجل الإرسال.
     */
    public function logExternal(Contract $contract, string $kind, string $channel, string $title, string $body): void
    {
        try {
            NotificationDispatch::query()->create([
                'user_id' => $contract->user_id ?: null,
                'contract_id' => $contract->id,
                'kind' => $kind,
                'channel' => $channel,
                'dedupe_key' => null,
                'title' => $title,
                'body' => $body,
                'push_result' => 'prepared',
                'recipients_count' => 1,
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('External message log failed', ['contract_id' => $contract->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * عدد مستلمي الإرسال الجماعي لشريحة.
     *
     * @param  array{segment?: string, city_id?: int|null}  $options
     */
    public function segmentCount(array $options = []): int
    {
        return $this->segmentQuery($options)->count();
    }

    /**
     * @param  array{segment?: string, city_id?: int|null}  $options
     */
    private function segmentQuery(array $options)
    {
        $segment = (string) ($options['segment'] ?? 'all');
        $query = User::query()
            ->where('is_active', 1)
            ->where('is_guest', false)
            ->whereNull('merged_into_user_id');

        if ($segment === 'has_active_contract') {
            $closed = \App\Models\ContractStatus::idsFor([\App\Models\ContractStatus::KEY_CANCELLED, \App\Models\ContractStatus::KEY_REFUNDED]);
            $query->whereHas('contracts', fn ($q) => $q->where('is_delete', 0)->where('is_completed', 1)
                ->when($closed !== [], fn ($w) => $w->where(fn ($x) => $x->whereNull('contract_status_id')->orWhereNotIn('contract_status_id', $closed))));
        } elseif ($segment === 'city' && ! empty($options['city_id'])) {
            $cityId = (int) $options['city_id'];
            $query->whereHas('contracts', fn ($q) => $q->where('is_delete', 0)->where('property_city_id', $cityId));
        }

        return $query;
    }

    // ───────────────────────── الإشعارات المجدولة ─────────────────────────

    public function orderAbandoned(Contract $contract, string $kind): ?Offer
    {
        $user = $this->ownerOf($contract);
        if ($user === null) {
            return null;
        }

        $order = $this->orderNumber($contract);

        return $this->notify(
            $user,
            $kind,
            'طلبك بانتظارك',
            "طلبك رقم {$order} ما زال بانتظارك — أكمل بياناتك ونوثّق عقدك اليوم",
            ['type' => 'order_reminder'],
            contract: $contract,
        );
    }

    public function awaitingPayment(Contract $contract): ?Offer
    {
        $user = $this->ownerOf($contract);
        if ($user === null) {
            return null;
        }

        return $this->notify(
            $user,
            self::KIND_AWAITING_PAYMENT_2H,
            'طلبك جاهز للدفع',
            'طلبك جاهز للدفع — ادفع الآن لنبدأ بإعداد مسودة عقدك',
            ['type' => 'payment_reminder'],
            contract: $contract,
        );
    }

    public function renewal(Contract $contract, string $kind, int $daysLeft): ?Offer
    {
        $user = $this->ownerOf($contract);
        if ($user === null) {
            return null;
        }

        $order = $this->orderNumber($contract);

        return $this->notify(
            $user,
            $kind,
            'قرب انتهاء عقدك',
            "ينتهي عقدك رقم {$order} بعد {$daysLeft} يوم — جدّده الآن بخطوات بسيطة",
            ['type' => 'renewal_reminder', 'days_left' => $daysLeft, 'renew' => true],
            contract: $contract,
        );
    }

    // ───────────────────────── داخلي ─────────────────────────

    private function claimDispatch(
        string $kind,
        int $userId,
        ?int $contractId,
        ?int $lessorChangeRequestId,
        ?string $dedupeKey,
        string $title,
        string $body,
        ?string $url,
    ): ?NotificationDispatch {
        if ($dedupeKey !== null && NotificationDispatch::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        try {
            return NotificationDispatch::query()->create([
                'user_id' => $userId,
                'contract_id' => $contractId,
                'lessor_change_request_id' => $lessorChangeRequestId,
                'kind' => $kind,
                'dedupe_key' => $dedupeKey,
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'push_result' => 'pending',
                'recipients_count' => 1,
            ]);
        } catch (QueryException $e) {
            // سباق: سجل آخر حجز نفس المفتاح.
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeOffer(
        User $user,
        string $kind,
        string $title,
        string $body,
        string $url,
        array $data,
        ?Contract $contract,
        ?LessorChangeRequest $lessorChange,
    ): ?Offer {
        try {
            $extra = array_filter($data, static fn ($v, $k) => ! in_array($k, [
                'type', 'contract_id', 'contract_uuid', 'order_number', 'url', 'kind', 'notification_id',
            ], true) && (is_scalar($v) || is_array($v) || $v === null), ARRAY_FILTER_USE_BOTH);

            return Offer::query()->create([
                'user_id' => (int) $user->id,
                'contract_id' => $contract?->id,
                'lessor_change_request_id' => $lessorChange?->id,
                'title' => $title,
                'body' => $body,
                'kind' => $kind,
                'url' => $url !== '' ? $url : null,
                'data' => $extra !== [] ? $extra : null,
                'is_active' => true,
                'is_read' => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Persist customer notification failed', [
                'kind' => $kind,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return string  sent | failed | no_token | disabled
     */
    private function push(User $user, string $title, string $body, array $payload): string
    {
        $token = (string) ($user->getRawOriginal('fcm_token') ?? $user->fcm_token ?? '');
        if (trim($token) === '') {
            return 'no_token';
        }

        if (! $this->firebase->isConfigured()) {
            return 'disabled';
        }

        try {
            $this->firebase->sendToToken($token, $title, $body, array_merge([
                'type' => 'customer_notification',
                'user_id' => (string) $user->id,
            ], $payload));

            return 'sent';
        } catch (\Throwable $e) {
            Log::warning('Customer push failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return 'failed';
        }
    }

    /**
     * @return array<string, string>
     */
    private function basePayload(string $kind, string $url, ?Contract $contract, ?LessorChangeRequest $lessorChange): array
    {
        $order = $contract ? $this->orderNumber($contract) : (string) ($lessorChange?->uuid ?? '');

        // متابعة دفعة (د): حالة الطلب في بيانات الـ push لتحديث الواجهات مباشرة.
        $status = $contract ? ContractFrontendStatus::for($contract) : null;

        return [
            'kind' => $kind,
            'url' => $url,
            'status' => (string) ($status['status'] ?? ($lessorChange?->status ?? '')),
            'status_label' => (string) ($status['status_label'] ?? ($lessorChange ? $lessorChange->statusLabel() : '')),
            'contract_id' => (string) ($contract?->id ?? ''),
            'contract_uuid' => (string) ($contract?->uuid ?? ''),
            'order_number' => $order,
            'lessor_change_request_id' => (string) ($lessorChange?->id ?? ''),
        ];
    }

    private function urlFor(?Contract $contract, ?LessorChangeRequest $lessorChange): ?string
    {
        if ($contract !== null) {
            return SmartLink::for($contract);
        }

        if ($lessorChange !== null) {
            return $lessorChange->smartLink();
        }

        return null;
    }

    private function ownerOf(Contract $contract): ?User
    {
        if ((int) $contract->user_id <= 0) {
            return null;
        }

        $user = $contract->relationLoaded('user') ? $contract->user : User::query()->find((int) $contract->user_id);

        return $user instanceof User ? $user : null;
    }

    private function orderNumber(Contract $contract): string
    {
        return (string) ($contract->uuid ?: str_pad((string) $contract->id, 6, '0', STR_PAD_LEFT));
    }
}
