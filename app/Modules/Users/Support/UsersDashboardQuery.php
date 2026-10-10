<?php

namespace App\Modules\Users\Support;

use App\Models\Payment;
use App\Models\RefundableContract;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;

class UsersDashboardQuery
{
    /**
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    public function make(Request $request, bool $withLists = true)
    {
        // دفعة (د) — ب6: صفحة العملاء = نطاق «عميل» الموحّد (بلا جلسات الزوار الفارغة والحسابات المدموجة).
        $query = $this->withTotals()->customers();
        $this->applyDashboardRelations($query, $withLists);

        if ($request->filled('platform')) {
            $platform = User::normalizePlatform((string) $request->input('platform')) ?? User::PLATFORM_WEBSITE;
            if ($platform === User::PLATFORM_WEBSITE) {
                $query->where(function ($q) {
                    $q->where('platform', User::PLATFORM_WEBSITE)
                        ->orWhereNull('platform')
                        ->orWhere('platform', '');
                });
            } else {
                $query->where('platform', $platform);
            }
        }

        if ($request->filled('banned') && $request->boolean('banned')) {
            $query->where('is_active', 0);
        } elseif ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active') ? 1 : 0);
        }

        if ($request->filled('search')) {
            $this->applyCustomerSearch($query, $request->string('search')->toString());
        }

        $createdAtFilter = $request->query('created_at');
        if (in_array($createdAtFilter, ['today', 'week', 'month', 'year'], true)) {
            $now = now();
            $ranges = [
                'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
                'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
                'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
                'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            ];
            [$start, $end] = $ranges[$createdAtFilter];
            $query->whereBetween('created_at', [$start, $end]);
        }

        return $query;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    public function withTotals()
    {
        return User::query()->addSelect([
            'total_paid_amount' => Payment::query()
                ->selectRaw('coalesce(sum(payments.amount), 0)')
                ->join('contracts', 'payments.contract_uuid', '=', 'contracts.uuid')
                ->whereColumn('contracts.user_id', 'users.id')
                ->where('payments.status', 'success'),
            // QA-F C3: المسترجع الفعلي من جدول refunds (نفس مصدر «المدفوع») — لا طلبات الاسترجاع.
            'total_refunded_amount' => \Illuminate\Support\Facades\Schema::hasTable('refunds')
                ? \App\Models\Refund::query()
                    ->selectRaw('coalesce(sum(refunds.amount), 0)')
                    ->join('contracts', 'refunds.contract_id', '=', 'contracts.id')
                    ->whereColumn('contracts.user_id', 'users.id')
                    ->where('refunds.status', \App\Models\Refund::STATUS_SUCCEEDED)
                : RefundableContract::query()
                    ->selectRaw('coalesce(sum(refund_amount), 0)')
                    ->whereColumn('refundable_contracts.user_id', 'users.id')
                    ->where('is_refunded', 1),
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $query
     */
    public function applyDashboardRelations($query, bool $withLists = true): void
    {
        $query->withCount([
            // دفعة (د) — ب5: نفس نطاق «جميع الطلبات» (الخطوة ≥ 4) حتى تتطابق الأعداد في كل مكان.
            'contracts as orders_count' => fn ($q) => $q->adminListed(),
            'contracts as completed_orders_count' => fn ($q) => $q->adminListed()->paymentPaid(true),
            'contracts as draft_orders_count' => fn ($q) => $q->adminListed()->where('is_draft', true),
            'contracts as incomplete_orders_count' => fn ($q) => $q->adminListed()->paymentPaid(false),
            'contracts as incomplete_drafts_count' => fn ($q) => $q->incompleteDraft(),
            'realEstate as real_estate_count',
            'unitReal as units_count',
        ]);

        if (! $withLists) {
            return;
        }

        $query->with([
            'realEstate.units',
            'realEstate.propertyType',
            'realEstate.propertyUsages',
            'realEstate.tenantEntityRegion',
            'realEstate.tenantEntityCity',
            'unitReal.unitType',
            'unitReal.unitUsage',
            'contracts' => fn ($q) => $q->adminListed()
                ->with($this->userContractRelations())
                ->latest(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $total = User::query()->customers()->count();
        $banned = User::query()->customers()->where('is_active', 0)->count();
        $apple = User::query()->customers()->where('platform', User::PLATFORM_APPLE_STORE)->count();
        $google = User::query()->customers()->where('platform', User::PLATFORM_GOOGLE_PLAY)->count();
        $website = User::query()->customers()
            ->where(function ($q) {
                $q->where('platform', User::PLATFORM_WEBSITE)
                    ->orWhereNull('platform')
                    ->orWhere('platform', '');
            })
            ->count();

        $weekStart = now()->startOfWeek();

        return [
            'total_customers' => $total,
            'new_this_week' => User::query()->customers()->where('users.created_at', '>=', $weekStart)->count(),
            'new_this_week_label' => 'عملاء جدد هذا الأسبوع',
            'total_customers_label' => 'إجمالي العملاء',
            'banned' => $banned,
            'banned_label' => 'المحظورون',
            'website_customers' => $website,
            'website_customers_label' => 'عملاء الموقع',
            'google_play_customers' => $google,
            'google_play_customers_label' => 'عملاء قوقل بلاي',
            'apple_store_customers' => $apple,
            'apple_store_customers_label' => 'عملاء أبل ستور',
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $query
     */
    private function applyCustomerSearch($query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';
        $numericId = null;
        $customerId = $term;
        if (preg_match('/^c-?(\d+)$/i', $term, $matches)) {
            $numericId = (int) $matches[1];
            $customerId = $matches[1];
        } elseif (ctype_digit($term)) {
            $numericId = (int) $term;
        }

        $query->where(function ($q) use ($like, $numericId, $customerId, $term) {
            $q->where('fname', 'like', $like)
                ->orWhere('lname', 'like', $like)
                ->orWhereRaw("concat(coalesce(fname,''), ' ', coalesce(lname,'')) like ?", [$like])
                ->orWhere('mobile', 'like', $like)
                ->orWhere('email', 'like', $like);

            if ($numericId !== null) {
                $q->orWhere('users.id', $numericId)
                    ->orWhereHas('contracts', function ($cq) use ($numericId, $customerId, $term) {
                        $cq->where('id', $numericId)
                            ->orWhere('uuid', 'like', '%'.$term.'%')
                            ->orWhere('uuid', 'like', '%'.$customerId.'%');
                    });
            } else {
                $q->orWhereHas('contracts', function ($cq) use ($term) {
                    $cq->where('uuid', 'like', '%'.$term.'%');
                });
            }
        });
    }

    /**
     * @return array<int|string, mixed>
     */
    private function userContractRelations(): array
    {
        return [
            'user',
            'receivedContract.employee',
            'acceptRetrunContractEmployee:id,name',
            'refundableContract',
            'contractStatus',
            'draftContractStatus',
            'contractPayments' => fn ($q) => $q->where('status', 'success'),
        ];
    }
}
