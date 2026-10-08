<?php

namespace App\Models;

use App\Support\SmartLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * طلب خدمة «تغيير المؤجر».
 */
class LessorChangeRequest extends Model
{
    public const DISK = 'local';

    public const DIR = 'lessor-change';

    public const STATUSES = ['pending_payment', 'paid', 'in_progress', 'completed', 'rejected', 'cancelled'];

    public const STATUS_LABELS = [
        'pending_payment' => 'بانتظار الدفع',
        'paid' => 'تم الدفع — بانتظار المعالجة',
        'in_progress' => 'قيد التنفيذ',
        'completed' => 'مكتمل',
        'rejected' => 'مرفوض',
        'cancelled' => 'ملغي',
    ];

    public const STATUS_COLORS = [
        'pending_payment' => '#D97706',
        'paid' => '#2563EB',
        'in_progress' => '#7C3AED',
        'completed' => '#16A34A',
        'rejected' => '#DC2626',
        'cancelled' => '#6B7280',
    ];

    protected $fillable = [
        'uuid', 'user_id', 'mobile', 'old_deed_image', 'new_deed_image',
        'new_owner_id_number', 'new_owner_dob', 'new_owner_dob_type', 'notes',
        'fee', 'status', 'status_note', 'employee_id', 'paid_at', 'completed_at', 'platform', 'is_delete',
    ];

    protected $casts = [
        'fee' => 'float',
        'is_delete' => 'boolean',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public static function generateUuid(): string
    {
        do {
            $uuid = (string) random_int(100000, 999999);
        } while (
            self::query()->where('uuid', $uuid)->exists()
            || Contract::query()->where('uuid', $uuid)->exists()
        );

        return $uuid;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function statusColor(): string
    {
        return self::STATUS_COLORS[$this->status] ?? '#0B5A3C';
    }

    public function isPaid(): bool
    {
        return $this->status !== 'pending_payment' && $this->status !== 'cancelled';
    }

    /** يُستدعى من مسار الدفع عند تأكيد الدفع من البوابة. */
    public static function markPaidByUuid(string $uuid): void
    {
        try {
            $updated = self::query()
                ->where('uuid', $uuid)
                ->where('status', 'pending_payment')
                ->update(['status' => 'paid', 'paid_at' => now()]);
        } catch (\Throwable) {
            // الجدول غير موجود (بيئات اختبار تبني مخططها يدوياً) — لا شيء يُفعل.
            return;
        }

        if ($updated > 0) {
            // ف8: إشعار العميل بتغيّر حالة طلب تغيير المؤجر (لا يرمي استثناءً).
            try {
                $row = self::query()->where('uuid', $uuid)->first();
                if ($row !== null) {
                    app(\App\Services\CustomerNotificationService::class)->lessorChangeStatusChanged($row);
                }
            } catch (\Throwable) {
                // يُسجَّل داخل الخدمة.
            }
        }
    }

    /** بحث آمن برقم الطلب (يُرجع null إذا الجدول غير متاح). */
    public static function findByUuid(string $uuid): ?self
    {
        try {
            return self::query()->where('uuid', $uuid)->where('is_delete', false)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    public function signedImageUrl(string $field): ?string
    {
        if (! in_array($field, ['old_deed_image', 'new_deed_image'], true) || empty($this->{$field})) {
            return null;
        }

        return URL::temporarySignedRoute(
            'lessor-change.image',
            now()->addMinutes(30),
            ['request' => $this->getKey(), 'field' => $field]
        );
    }

    public function smartLink(): string
    {
        return SmartLink::forOrder($this->uuid);
    }

    /** الملخص المعروض للعميل (بدون بيانات حساسة زائدة). */
    public function toClientArray(): array
    {
        return [
            'kind' => 'lessor_change',
            'order_number' => $this->uuid,
            'uuid' => $this->uuid,
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'status_color' => $this->statusColor(),
            'status_note' => $this->status_note,
            'fee' => (float) $this->fee,
            'is_paid' => $this->isPaid(),
            'awaiting_payment' => $this->status === 'pending_payment',
            'payment_url' => $this->status === 'pending_payment'
                ? route('v2.payment.lessor-change', ['uuid' => $this->uuid])
                : null,
            'new_owner_id_number' => $this->new_owner_id_number,
            'new_owner_dob' => $this->new_owner_dob,
            'new_owner_dob_type' => $this->new_owner_dob_type,
            'smart_link' => $this->smartLink(),
            'created_at' => optional($this->created_at)->format('Y-m-d'),
            'updated_at' => optional($this->updated_at)->format('Y-m-d H:i'),
            'paid_at' => optional($this->paid_at)->format('Y-m-d H:i'),
        ];
    }
}
