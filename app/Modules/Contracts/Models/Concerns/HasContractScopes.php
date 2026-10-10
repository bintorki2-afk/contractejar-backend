<?php

namespace App\Modules\Contracts\Models\Concerns;

use App\Models\Contract;
use App\Models\Payment;

trait HasContractScopes
{
    /**
     * QA-F C6: فلتر «مدفوع / غير مدفوع» من الدفعات الفعلية (نفس مصدر شارة الدفع)، لا من is_completed.
     * مدفوع = مجموع الدفعات الناجحة > مجموع الاسترجاعات الناجحة.
     */
    public function scopePaymentPaid($query, bool $paid = true)
    {
        $payments = Payment::query()
            ->selectRaw('coalesce(sum(payments.amount), 0)')
            ->where('payments.status', 'success')
            ->where(function ($q) {
                $q->whereColumn('payments.contract_uuid', 'contracts.uuid')
                    ->orWhereRaw(\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite'
                        ? "payments.contract_uuid LIKE (CAST(contracts.uuid AS TEXT) || '-%')"
                        : "payments.contract_uuid LIKE CONCAT(CAST(contracts.uuid AS CHAR), '-%')");
                if (\App\Support\SchemaCache::hasColumn('payments', 'contract_id')) {
                    $q->orWhereColumn('payments.contract_id', 'contracts.id');
                }
            });

        $sql = '('.$payments->toSql().')';
        $bindings = $payments->getBindings();

        if (\App\Support\SchemaCache::hasTable('refunds')) {
            $refunds = \App\Models\Refund::query()
                ->selectRaw('coalesce(sum(refunds.amount), 0)')
                ->whereColumn('refunds.contract_id', 'contracts.id')
                ->where('refunds.status', \App\Models\Refund::STATUS_SUCCEEDED);
            $sql .= ' - ('.$refunds->toSql().')';
            $bindings = array_merge($bindings, $refunds->getBindings());
        }

        return $query->whereRaw('('.$sql.') '.($paid ? '>' : '<=').' 0.009', $bindings);
    }

    public function scopeNotDeleted($query)
    {
        return $query->where('is_delete', 0);
    }

    /**
     * Public/mobile API: only the authenticated owner's non-deleted contracts.
     */
    public function scopeOwnedBy($query, int $userId)
    {
        return $query->where('user_id', $userId)->visibleToOwner();
    }

    /**
     * متابعة دفعة (د) — القرار الآمن: طلب مدفوع نُقل للسلة من اللوحة يبقى ظاهراً لصاحبه (بحالة «ملغي» مع فاتورته)؛
     * غيره من المحذوفات لا يظهر.
     */
    public function scopeVisibleToOwner($query)
    {
        $table = $query->getModel()->getTable();
        $hasTrash = \App\Support\SchemaCache::hasColumn('contracts', 'trashed_at');

        return $query->where(function ($q) use ($table, $hasTrash) {
            $q->where($table.'.is_delete', 0);
            if ($hasTrash) {
                $q->orWhere(fn ($t) => $t->where($table.'.is_delete', 1)->whereNotNull($table.'.trashed_at')->where($table.'.is_completed', 1));
            }
        });
    }

    public static function requireApiUserId(): int
    {
        $userId = auth()->id();

        if ($userId === null) {
            abort(401, trans('api.unauthenticated'));
        }

        return (int) $userId;
    }

    /**
     * Public/mobile API: caller must be logged in and own the contract.
     */
    public static function findOwnedOrFail(int|string $id, ?int $userId = null): Contract
    {
        return static::query()
            ->ownedBy($userId ?? static::requireApiUserId())
            ->findOrFail($id);
    }

    /**
     * Public/mobile API: caller must be logged in and own the contract UUID.
     */
    public static function findOwnedByUuidOrFail(string $uuid, ?int $userId = null): Contract
    {
        return static::query()
            ->ownedBy($userId ?? static::requireApiUserId())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * Admin dashboard: employee is already authenticated (and usually permission-gated).
     * Staff may open any contract; ownership is not required.
     */
    public static function findForStaffOrFail(int|string $id): Contract
    {
        if (! auth()->check()) {
            abort(401, trans('api.unauthenticated'));
        }

        return static::query()->findOrFail($id);
    }

    /**
     * قاعدة الظهور الموحّدة (دفعة د — ب5): «طلب» = بلغ الخطوة 4 فأكثر (صك + عنوان + مالك مُرسلة).
     * نفس الحد للعميل (visibleToCustomer) ولقوائم اللوحة وعدّاداتها وملف العميل في اللوحة.
     * ما دون ذلك «مسودة غير مكتملة» (scopeIncompleteDraft) — تبويب «غير مكتمل» فقط. انظر ARCHITECTURE.md.
     */
    public function scopeReachedAdminOrderStep($query, ?int $minStep = null)
    {
        return $query->where('step', '>=', $minStep ?? self::CUSTOMER_VISIBLE_MIN_STEP)->notSynthetic();
    }

    /** «طلب» ظاهر في اللوحة: غير محذوف + بلغ الخطوة 4 (وليس بيانات فحص اصطناعية). */
    public function scopeAdminListed($query)
    {
        return $query->where('is_delete', 0)->where('step', '>=', self::CUSTOMER_VISIBLE_MIN_STEP)->notSynthetic();
    }

    /** ب19: استبعاد طلبات الفحص اليومي الاصطناعية. */
    public function scopeNotSynthetic($query)
    {
        if (! \App\Support\SchemaCache::hasColumn('contracts', 'is_synthetic')) {
            return $query;
        }
        $table = $query->getModel()->getTable();

        return $query->where(fn ($q) => $q->whereNull($table.'.is_synthetic')->orWhere($table.'.is_synthetic', false));
    }

    /**
     * Drafts the CUSTOMER may see/resume: deed (step 1), address (step 2) and
     * owner (step 3) must all be submitted, i.e. the next step is 4+.
     * Earlier drafts stay invisible to the customer (admin lists are unaffected).
     */
    public const CUSTOMER_VISIBLE_MIN_STEP = 4;

    public function scopeVisibleToCustomer($query)
    {
        return $query->where('step', '>=', self::CUSTOMER_VISIBLE_MIN_STEP);
    }

    /**
     * «مسودة غير مكتملة» (دفعة د — ب5): لم يتجاوز العميل الخطوة 3 ولم يدفع.
     * تظهر في اللوحة تحت تبويب «غير مكتمل» فقط، ولا تظهر للعميل ولا في عدّادات «جميع الطلبات».
     */
    public function scopeIncompleteDraft($query)
    {
        return $query->where('is_delete', 0)
            ->where('step', '<', self::CUSTOMER_VISIBLE_MIN_STEP)
            ->where('is_completed', 0)
            ->notSynthetic();
    }

    public function scopeIncomplete($query)
    {
        return $query->where('is_completed', 0);
    }

    /**
     * Latest-incomplete lookup for one user, optionally limited to housing|commercial.
     */
    public function scopeIncompleteForUser($query, int $userId, ?string $contractType = null)
    {
        $query->where('user_id', $userId)
            ->incomplete()
            ->notDeleted();

        if (is_string($contractType) && $contractType !== '') {
            $query->where('contract_type', $contractType);
        }

        return $query;
    }

    public function scopeCompleted($query)
    {
        return $query->where('is_completed', 1);
    }

    public function scopeDraft($query)
    {
        return $query->where('is_draft', true);
    }

    public function scopeGetCompeleteContract($query, $uuids)
    {
        return $query = Contract::whereIn('uuid', $uuids)->where('is_completed', 1)->where('is_review', 0);
    }

    public function scopeGetPaymentContract($query)
    {
        return $query = Payment::where('status', 'success')->pluck('contract_uuid');
    }

    public function scopeGetReview($query)
    {
        return $query = Contract::where('is_review', true);
    }
}
