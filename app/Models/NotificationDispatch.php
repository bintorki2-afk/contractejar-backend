<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجل إرسال إشعارات العملاء (ف8) — يمنع تكرار الإشعار لنفس النوع ونفس الطلب.
 */
class NotificationDispatch extends Model
{
    protected $fillable = [
        'user_id',
        'contract_id',
        'lessor_change_request_id',
        'kind',
        'dedupe_key',
        'title',
        'body',
        'url',
        'push_result',
        'recipients_count',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'recipients_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function lessorChangeRequest(): BelongsTo
    {
        return $this->belongsTo(LessorChangeRequest::class, 'lessor_change_request_id');
    }

    public static function dedupeKey(string $kind, ?int $contractId, ?int $lessorChangeRequestId, ?string $qualifier = null): ?string
    {
        $target = match (true) {
            $contractId !== null && $contractId > 0 => 'contract:'.$contractId,
            $lessorChangeRequestId !== null && $lessorChangeRequestId > 0 => 'lessor:'.$lessorChangeRequestId,
            default => null,
        };

        if ($target === null) {
            return null;
        }

        $key = $kind.'|'.$target;
        if ($qualifier !== null && $qualifier !== '') {
            $key .= '|'.$qualifier;
        }

        return mb_substr($key, 0, 160);
    }
}
