<?php

namespace App\Services\Admin\Analytics;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UserAnalyticsService
{
    use AnalyticsHelper;

    public function getNewUsersAnalytics(): array
    {
        // دفعة (د) — ب6: نفس نطاق صفحة العملاء (User::customers).
        $getByPeriod = fn ($start, $end) => User::query()->customers()->whereBetween('users.created_at', [$start, $end])->count();
        $today = $getByPeriod(Carbon::today(), Carbon::tomorrow());
        $week = $getByPeriod(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek());
        $month = $getByPeriod(Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth());
        $year = $getByPeriod(Carbon::now()->startOfYear(), Carbon::now()->endOfYear());
        $total = User::query()->customers()->count();

        return [
            'today' => ['count' => $today, 'percentage_change' => $this->calculatePercentageChange($today, $getByPeriod(Carbon::yesterday(), Carbon::today()))],
            'week' => ['count' => $week, 'percentage_change' => $this->calculatePercentageChange($week, $getByPeriod(Carbon::now()->subWeek()->startOfWeek(), Carbon::now()->subWeek()->endOfWeek()))],
            'month' => ['count' => $month, 'percentage_change' => $this->calculatePercentageChange($month, $getByPeriod(Carbon::now()->subMonth()->startOfMonth(), Carbon::now()->subMonth()->endOfMonth()))],
            'year' => ['count' => $year, 'percentage_change' => $this->calculatePercentageChange($year, $getByPeriod(Carbon::now()->subYear()->startOfYear(), Carbon::now()->subYear()->endOfYear()))],
            'total' => $total,
        ];
    }

    public function getUserActivityRate(): array
    {
        $totalUsers = User::count();
        $activeUsers = User::where('is_active', 1)->count();

        return [
            'rate' => $totalUsers > 0 ? round(($activeUsers / $totalUsers) * 100, 2) : 0,
            'active_users_count' => $activeUsers,
            'total_users_count' => $totalUsers,
        ];
    }

    public function getInactiveUsers(): array
    {
        $totalUsers = User::count();
        $inactiveUsers = User::where('is_active', 0)->count();

        return [
            'count' => $inactiveUsers,
            'percentage' => $totalUsers > 0 ? round(($inactiveUsers / $totalUsers) * 100, 2) : 0,
        ];
    }

    public function getClientsWithRefundsCount(): int
    {
        $userIds = DB::table('payments')
            ->join('contracts', 'payments.contract_uuid', '=', 'contracts.uuid')
            ->where('payments.status', 'failed')
            ->distinct()
            ->pluck('contracts.user_id')
            ->filter();

        return User::whereIn('id', $userIds)->count();
    }

    public function countUsersWithCompletedOrders(): int
    {
        // whereHas instead of withCount()+having(): HAVING without GROUP BY is a MySQL-only extension
        // (500 "HAVING clause on a non-aggregate query" on sqlite / PostgreSQL).
        return User::whereHas('contracts', fn ($q) => $q->where('is_completed', 1)->where('is_delete', 0))->count();
    }

    public function countUsersWithIncompleteOrders(): int
    {
        return User::whereHas('contracts', fn ($q) => $q->where('is_completed', 0)->where('is_delete', 0))->count();
    }

    public function countUsersWithOrders(): int
    {
        return User::has('contracts')->count();
    }

    public function countUsersWithRealEstates(): int
    {
        return User::has('realEstate')->count();
    }

    public function countUsersWithUnits(): int
    {
        return User::has('unitReal')->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTopCustomersByCompletedOrders(int $limit = 10): array
    {
        return $this->fetchTopAnalyticsClients(
            fn (Builder $q) => $q->having('completed_orders_count', '>', 0)
                ->orderByDesc('completed_orders_count'),
            'completed_orders_count',
            $limit
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTopCustomersByIncompleteOrders(int $limit = 10): array
    {
        return $this->fetchTopAnalyticsClients(
            fn (Builder $q) => $q->having('incomplete_orders_count', '>', 0)
                ->orderByDesc('incomplete_orders_count'),
            'incomplete_orders_count',
            $limit
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTopCustomersByTotalOrders(int $limit = 10): array
    {
        return $this->fetchTopAnalyticsClients(
            fn (Builder $q) => $q->having('total_orders_count', '>', 0)
                ->orderByDesc('total_orders_count'),
            'total_orders_count',
            $limit
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTopCustomersByRefunds(int $limit = 10): array
    {
        return $this->fetchTopAnalyticsClients(
            fn (Builder $q) => $q->having('refunds_count', '>', 0)
                ->orderByDesc('refunds_count')
                ->orderByDesc('total_refunds_amount'),
            'refunds_count',
            $limit
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTopCustomersByRealEstates(int $limit = 10): array
    {
        return $this->fetchTopAnalyticsClients(
            fn (Builder $q) => $q->having('properties_count', '>', 0)
                ->orderByDesc('properties_count'),
            'properties_count',
            $limit
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTopCustomersByUnits(int $limit = 10): array
    {
        return $this->fetchTopAnalyticsClients(
            fn (Builder $q) => $q->having('units_count', '>', 0)
                ->orderByDesc('units_count'),
            'units_count',
            $limit
        );
    }

    public function getTopCustomers(): array
    {
        return [
            'completed_orders' => $this->getTopCustomersByCompletedOrders(),
            'incomplete_orders' => $this->getTopCustomersByIncompleteOrders(),
            'total_orders' => $this->getTopCustomersByTotalOrders(),
            'refunds' => $this->getTopCustomersByRefunds(),
            'real_estates' => $this->getTopCustomersByRealEstates(),
            'units' => $this->getTopCustomersByUnits(),
        ];
    }

    protected function topAnalyticsClientsQuery(): Builder
    {
        return User::query()
            ->select([
                'users.id',
                'users.fname',
                'users.lname',
                'users.email',
                'users.mobile',
                'users.is_active',
                'users.created_at',
            ])
            // GROUP BY the primary key makes the HAVING(<withCount alias>) filters below valid on
            // every driver (sqlite/PostgreSQL reject HAVING on a non-aggregate query; MySQL accepts
            // the PK as functionally determining the other users.* columns).
            ->groupBy('users.id')
            ->withCount([
                'contracts as completed_orders_count' => fn ($q) => $q->where('is_completed', 1)->where('is_delete', 0),
                'contracts as incomplete_orders_count' => fn ($q) => $q->where('is_completed', 0)->where('is_delete', 0),
                'contracts as total_orders_count' => fn ($q) => $q->where('is_delete', 0),
                'realEstate as properties_count',
                'unitReal as units_count',
            ])
            ->selectSub(
                DB::table('payments')
                    ->join('contracts', 'payments.contract_uuid', '=', 'contracts.uuid')
                    ->whereColumn('contracts.user_id', 'users.id')
                    ->where('payments.status', 'success')
                    ->selectRaw('COALESCE(SUM(payments.amount), 0)'),
                'total_paid_amount'
            )
            ->selectSub(
                DB::table('payments')
                    ->join('contracts', 'payments.contract_uuid', '=', 'contracts.uuid')
                    ->whereColumn('contracts.user_id', 'users.id')
                    ->where('payments.status', 'failed')
                    ->selectRaw('COALESCE(SUM(payments.amount), 0)'),
                'total_refunds_amount'
            )
            ->selectSub(
                DB::table('contracts')
                    ->join('payments', 'contracts.uuid', '=', 'payments.contract_uuid')
                    ->whereColumn('contracts.user_id', 'users.id')
                    ->where('payments.status', 'failed')
                    ->selectRaw('COUNT(DISTINCT contracts.id)'),
                'refunds_count'
            );
    }

    /**
     * @param  callable(Builder): void  $applySortAndFilter
     * @return array<int, array<string, mixed>>
     */
    protected function fetchTopAnalyticsClients(callable $applySortAndFilter, string $metricField, int $limit): array
    {
        $query = $this->topAnalyticsClientsQuery();
        $applySortAndFilter($query);

        return $this->mapAnalyticsClients($query->limit($limit)->get(), $metricField);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function mapAnalyticsClients(Collection $users, string $metricField): array
    {
        return $users->map(function (User $user) use ($metricField) {
            $metricCount = (int) ($user->{$metricField} ?? 0);

            return [
                'id' => $user->id,
                'name' => trim($user->fname.' '.$user->lname),
                'email' => $user->email,
                'phone' => $user->mobile,
                'is_active' => (bool) $user->is_active,
                'status' => $user->is_active ? 'active' : 'inactive',
                'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
                'created_at_label' => $user->created_at
                    ? date('j F Y - h:i A', strtotime($user->created_at))
                    : null,
                'properties_count' => (int) $user->properties_count,
                'units_count' => (int) $user->units_count,
                'completed_orders_count' => (int) $user->completed_orders_count,
                'incomplete_orders_count' => (int) $user->incomplete_orders_count,
                'total_orders_count' => (int) $user->total_orders_count,
                'total_paid_amount' => round((float) $user->total_paid_amount, 2),
                'refunds_count' => (int) $user->refunds_count,
                'total_refunds_amount' => round((float) $user->total_refunds_amount, 2),
                'count' => $metricCount,
                'metric_count' => $metricCount,
            ];
        })->values()->all();
    }
}
