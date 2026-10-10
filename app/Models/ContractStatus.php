<?php

namespace App\Models;

use App\Support\ContractFrontendStatus;
use App\Support\ContractStatusCase;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Support\SchemaCache;

/**
 * حالات الطلب في اللوحة.
 *
 * دفعة (د): كل حالة تحمل مفتاحاً ثابتاً في العمود `status_key` (new, paid, under_review, received,
 * received_by_employee, whatsapp_draft, ejar_authenticated, completed, cancelled, on_hold, refunded).
 * الكود يحدّد الحالة بالمفتاح عبر {@see self::idFor()} — وليس برقم الصف (الأرقام تختلف بين البيئات).
 *
 * ملاحظة: الخاصية المُلحقة `status_case` (نموذج الحقول الإضافية للّوحة) تبقى كما هي؛ المفتاح الثابت اسمه `status_key`.
 */
class ContractStatus extends Model
{
    use HasFactory;

    /** جديد — default status for contracts arriving from the app/website (not received yet). */
    public const NEW_ID = 1;

    /**
     * @deprecated «مستلم» — احتياط فقط عندما لا يوجد صف بمفتاح received_by_employee/received.
     *             استخدم {@see self::receivedId()}.
     */
    public const RECEIVED_ID = 6;

    /** @deprecated حالة قديمة (أُلغيت مرحلة المسودة في دفعة هـ) — تبقى للبيانات التاريخية فقط. */
    public const WHATSAPP_DRAFT_ID = 8;

    /** توثيق العقد في إيجار (احتياط — المفتاح ejar_authenticated هو المرجع). */
    public const EJAR_AUTHENTICATION_ID = 9;

    public const KEY_NEW = 'new';

    public const KEY_PAID = 'paid';

    public const KEY_UNDER_REVIEW = 'under_review';

    public const KEY_RECEIVED = 'received';

    public const KEY_RECEIVED_BY_EMPLOYEE = 'received_by_employee';

    public const KEY_WHATSAPP_DRAFT = 'whatsapp_draft';

    public const KEY_EJAR_AUTHENTICATED = 'ejar_authenticated';

    public const KEY_COMPLETED = 'completed';

    public const KEY_CANCELLED = 'cancelled';

    public const KEY_ON_HOLD = 'on_hold';

    public const KEY_REFUNDED = 'refunded';

    public const KEY_WAITING_SUPERVISOR = 'waiting_supervisor';

    /** ترتيب مسار الطلب الرئيسي (بدون الحالات الجانبية). دفعة (هـ): بلا مرحلة المسودة. */
    public const FLOW = [
        self::KEY_NEW,
        self::KEY_PAID,
        self::KEY_UNDER_REVIEW,
        self::KEY_RECEIVED_BY_EMPLOYEE,
        self::KEY_EJAR_AUTHENTICATED,
        self::KEY_COMPLETED,
    ];

    /**
     * مفاتيح قديمة تبقى بيانات تاريخية فقط (لا تظهر في التبويبات ولا المسار ولا قوائم الاختيار).
     * دفعة (و) — D1: «مستلم» (received) دُمجت في «مستلم من الموظف» (received_by_employee).
     */
    public const LEGACY_KEYS = [self::KEY_WHATSAPP_DRAFT, self::KEY_RECEIVED];

    /** مفاتيح قديمة ⇒ المفتاح الحالي البديل (للفلاتر القادمة من واجهات قديمة). */
    public const LEGACY_KEY_ALIASES = [self::KEY_RECEIVED => self::KEY_RECEIVED_BY_EMPLOYEE];

    /** دفعة (و) — D2: حالات لا تُوضع يدوياً من اللوحة (يضعها الخادم تلقائياً). */
    public const AUTO_ONLY_KEYS = [self::KEY_REFUNDED];

    public const REFUND_AUTO_ONLY_MESSAGE = 'يتحوّل الطلب إلى مسترجع تلقائياً بعد تنفيذ الاسترجاع من ميسر';

    /** الحالات الجانبية. */
    public const SIDE_STATES = [self::KEY_CANCELLED, self::KEY_ON_HOLD, self::KEY_REFUNDED];

    /** كل المفاتيح المعروفة. */
    public const KEYS = [
        self::KEY_NEW,
        self::KEY_PAID,
        self::KEY_UNDER_REVIEW,
        self::KEY_RECEIVED,
        self::KEY_RECEIVED_BY_EMPLOYEE,
        self::KEY_WHATSAPP_DRAFT,
        self::KEY_EJAR_AUTHENTICATED,
        self::KEY_COMPLETED,
        self::KEY_CANCELLED,
        self::KEY_ON_HOLD,
        self::KEY_REFUNDED,
        self::KEY_WAITING_SUPERVISOR,
    ];

    protected $fillable = [
        'name',
        'status_key',
        'color',
        'color_text',
        'description',
        'client_explanation',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    protected $appends = ['created_at_label', 'status_case', 'manual_selectable', 'manual_hint'];

    /** @var array<string, int|null>|null */
    private static ?array $idsByKey = null;

    protected static function booted(): void
    {
        static::saving(function (ContractStatus $status): void {
            if (! self::hasKeyColumn()) {
                return;
            }
            if (blank($status->getAttribute('status_key')) && filled($status->name)) {
                $key = ContractFrontendStatus::knownKeyFromName($status->name);
                if ($key !== null && ! static::query()->where('status_key', $key)->whereKeyNot($status->getKey())->exists()) {
                    $status->setAttribute('status_key', $key);
                }
            }
        });

        static::saved(static fn () => self::flushKeyCache());
        static::deleted(static fn () => self::flushKeyCache());
    }

    public static function flushKeyCache(): void
    {
        self::$idsByKey = null;
    }

    /**
     * رقم صف الحالة بمفتاحها الثابت (مخزّن في الذاكرة لكل عملية).
     * يبحث في `status_key` أولاً ثم بالاسم العربي (للصفوف القديمة قبل التعبئة).
     */
    public static function idFor(string $key): ?int
    {
        if (self::$idsByKey === null) {
            self::$idsByKey = [];
            try {
                $columns = ['id', 'name'];
                $hasKey = self::hasKeyColumn();
                if ($hasKey) {
                    $columns[] = 'status_key';
                }
                $rows = static::query()->orderBy('id')->get($columns);
                foreach ($rows as $row) {
                    $rowKey = $hasKey ? $row->getAttribute('status_key') : null;
                    if (filled($rowKey) && ! isset(self::$idsByKey[$rowKey])) {
                        self::$idsByKey[$rowKey] = (int) $row->id;
                    }
                }
                foreach ($rows as $row) {
                    $nameKey = ContractFrontendStatus::knownKeyFromName($row->name);
                    if ($nameKey !== null && ! isset(self::$idsByKey[$nameKey])) {
                        self::$idsByKey[$nameKey] = (int) $row->id;
                    }
                }
            } catch (\Throwable) {
                self::$idsByKey = null;

                return null;
            }
        }

        return self::$idsByKey[$key] ?? null;
    }

    /** @param  list<string>  $keys  @return list<int> */
    public static function idsFor(array $keys): array
    {
        $ids = [];
        foreach ($keys as $key) {
            $id = self::idFor($key);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public static function byKey(string $key): ?self
    {
        $id = self::idFor($key);

        return $id !== null ? static::query()->find($id) : null;
    }

    /** مفتاح صف حالة برقمه (null إذا غير معروف). */
    public static function keyForId(?int $id): ?string
    {
        if ($id === null || $id <= 0) {
            return null;
        }
        self::idFor(self::KEY_NEW); // يملأ الكاش
        $key = array_search($id, self::$idsByKey ?? [], true);

        return $key === false ? null : (string) $key;
    }

    /** حالة «مسترجع» (refunded). */
    public static function refundedId(): ?int
    {
        return self::idFor(self::KEY_REFUNDED);
    }

    /** الحالة التي يضعها استلام الموظف للطلب: «مستلم من الموظف» (D1: «مستلم» قديم — احتياط فقط لقواعد بيانات بلا الصف الجديد). */
    public static function receivedId(): int
    {
        return self::idFor(self::KEY_RECEIVED_BY_EMPLOYEE)
            ?? self::idFor(self::KEY_RECEIVED)
            ?? self::RECEIVED_ID;
    }

    public static function newId(): int
    {
        return self::idFor(self::KEY_NEW) ?? self::NEW_ID;
    }

    private static function hasKeyColumn(): bool
    {
        try {
            return SchemaCache::hasColumn('contract_statuses', 'status_key');
        } catch (\Throwable) {
            return false;
        }
    }

    /** دفعة (و) — D2: هل يمكن للموظف اختيار هذه الحالة يدوياً من قوائم تغيير الحالة؟ */
    public function getManualSelectableAttribute(): bool
    {
        return ! in_array($this->attributes['status_key'] ?? null, self::AUTO_ONLY_KEYS, true);
    }

    public function getManualHintAttribute(): ?string
    {
        return ($this->attributes['status_key'] ?? null) === self::KEY_REFUNDED ? self::REFUND_AUTO_ONLY_MESSAGE : null;
    }

    /**
     * Get formatted created at label
     */
    public function getCreatedAtLabelAttribute()
    {
        return date('Y-m-d H:i A', strtotime($this->created_at));
    }

    /**
     * Extra fields the admin UI must collect when changing a contract to this status.
     *
     * @return array{key: string, fields: list<array<string, mixed>>}|null
     */
    public function getStatusCaseAttribute(): ?array
    {
        return ContractStatusCase::schemaFor((int) $this->id, $this->name, $this->attributes['status_key'] ?? null);
    }
}
