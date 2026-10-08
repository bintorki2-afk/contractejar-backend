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
    ];

    public const KIND_LABELS = [
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
    public function broadcast(string $kind, string $title, string $body, ?string $url = null, array $data = []): array
    {
        $recipients = 0;
        $pushResult = ['sent' => 0, 'failed' => 0, 'topic_sent' => false, 'missing_token' => false];

        try {
            $now = now();
            User::query()
                ->where('is_active', 1)
                ->where('is_guest', false)
                ->whereNull('merged_into_user_id')
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
        } catch (\Throwable $e) {
            Log::warning('Broadcast notification storage failed', ['kind' => $kind, 'error' => $e->getMessage()]);
        }

        try {
            $pushResult = $this->firebase->sendToAllUsers($title, $body, array_merge([
                'kind' => $kind,
                'url' => (string) ($url ?? ''),
            ], $data));
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

        return ['recipients' => $recipients, 'push' => $pushResult];
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

        return $this->notify(
            $user,
            self::KIND_STATUS_CHANGED,
            'تحديث حالة طلبك',
            "طلبك رقم {$order}: ".($label !== '' ? $label : 'تم تحديث حالة طلبك'),
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

        return [
            'kind' => $kind,
            'url' => $url,
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
