<?php

namespace App\Modules\Contracts\Models\Concerns;

use App\Models\Contract;
use App\Models\Payment;

trait HasContractScopes
{
    public function scopeNotDeleted($query)
    {
        return $query->where('is_delete', 0);
    }

    /**
     * Public/mobile API: only the authenticated owner's non-deleted contracts.
     */
    public function scopeOwnedBy($query, int $userId)
    {
        return $query->where('user_id', $userId)->notDeleted();
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
     * Contracts that reached at least the given step (default: 3).
     * Used by admin order lists and API v2 contract lists.
     */
    public function scopeReachedAdminOrderStep($query, int $minStep = 3)
    {
        return $query->where('step', '>=', $minStep);
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
