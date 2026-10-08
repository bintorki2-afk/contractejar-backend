<?php

namespace App\Services\Marketing\Tracking;

use App\Models\AdSpendDaily;
use App\Models\Contract;
use App\Models\Payment;
use App\Support\Marketing\AttributionSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MarketingAttributionQueries
{
    public const PAID_SOURCES = ['google', 'meta', 'tiktok', 'snapchat', 'twitter'];

    public const CHANNELS = [
        'google' => ['ar' => 'قوقل', 'en' => 'Google', 'color' => 'blue'],
        'meta' => ['ar' => 'ميتا', 'en' => 'Meta', 'color' => 'purple'],
        'tiktok' => ['ar' => 'تيك توك', 'en' => 'TikTok', 'color' => 'black'],
        'snapchat' => ['ar' => 'سناب', 'en' => 'Snapchat', 'color' => 'yellow'],
        'twitter' => ['ar' => 'إكس', 'en' => 'X', 'color' => 'gray'],
    ];

    /** @var array<string, bool> */
    private array $schemaCache = [];

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    public function contractBase(?array $range)
    {
        $query = Contract::query()->notDeleted()->reachedAdminOrderStep();
        $this->joinUsersForAttribution($query);
        $this->applyDateRange($query, 'contracts.created_at', $range);

        return $query;
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    public function revenueQuery(?array $range)
    {
        $driver = DB::connection()->getDriverName();
        $query = Payment::query()
            ->where('payments.status', 'success')
            ->join('contracts', function ($join) use ($driver) {
                $join->on('payments.contract_uuid', '=', 'contracts.uuid');
                if ($driver === 'sqlite') {
                    $join->orWhereRaw("payments.contract_uuid LIKE contracts.uuid || '-%'");
                } else {
                    $join->orWhereRaw("payments.contract_uuid LIKE CONCAT(contracts.uuid, '-%')");
                }
            })
            ->where('contracts.is_delete', 0)
            ->where('contracts.step', '>=', Contract::CUSTOMER_VISIBLE_MIN_STEP); // دفعة (د) — ب5: نفس قاعدة الطلب

        $this->joinUsersForAttribution($query);
        $this->applyDateRange($query, 'contracts.created_at', $range);

        return $query;
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    public function contractAggregates(?array $range)
    {
        return $this->withoutDefaultSelect($this->contractBase($range));
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    public function revenueAggregates(?array $range)
    {
        return $this->withoutDefaultSelect($this->revenueQuery($range));
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    public function campaignSpendQuery(?array $range)
    {
        $query = AdSpendDaily::query()->where(function ($q) {
            $q->where('keyword', '')->orWhereNull('keyword');
        });
        $this->applySpendRange($query, $range);

        return $query;
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    public function keywordSpendQuery(?array $range)
    {
        $query = AdSpendDaily::query()
            ->whereNotNull('keyword')
            ->where('keyword', '!=', '');
        $this->applySpendRange($query, $range);

        return $query;
    }

    public function hasAdSpendTable(): bool
    {
        return $this->tableExists('ad_spend_dailies');
    }

    public function hasAttributionField(string $column): bool
    {
        return $this->columnExists('contracts', $column) || $this->columnExists('users', $column);
    }

    /**
     * SQL for utm_source: contracts first (if the column exists), else users, else 'direct'.
     */
    public function sourceExpression(string $table = 'contracts'): string
    {
        return $this->coalesceField('utm_source', "'direct'");
    }

    public function termExpression(): string
    {
        return $this->coalesceField('utm_term', 'NULL');
    }

    public function campaignExpression(): string
    {
        return $this->coalesceField('utm_campaign', 'NULL');
    }

    public function contentExpression(): string
    {
        return $this->coalesceField('utm_content', 'NULL');
    }

    /**
     * GROUP BY the first N select-list expressions by position.
     *
     * MySQL ONLY_FULL_GROUP_BY does not treat COALESCE(users.utm_source, …) in
     * GROUP BY as matching the same expression in SELECT, so repeating the SQL
     * fails with "isn't in GROUP BY". Positional GROUP BY works on MySQL and SQLite.
     */
    public function groupBySelectPositions(int $count = 1): string
    {
        return implode(', ', range(1, max(1, $count)));
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return Collection<string, mixed>
     */
    public function spendByPlatform(?array $range): Collection
    {
        if (! $this->hasAdSpendTable()) {
            return collect();
        }

        return $this->campaignSpendQuery($range)
            ->select('platform')
            ->selectRaw('COALESCE(SUM(spend), 0) as total_spend')
            ->groupBy('platform')
            ->pluck('total_spend', 'platform');
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return array{impressions: int, clicks: int}
     */
    public function spendFunnelTotals(?array $range): array
    {
        if (! $this->hasAdSpendTable()) {
            return ['impressions' => 0, 'clicks' => 0];
        }

        $row = $this->campaignSpendQuery($range)
            ->selectRaw('COALESCE(SUM(impressions), 0) as impressions')
            ->selectRaw('COALESCE(SUM(clicks), 0) as clicks')
            ->first();

        return [
            'impressions' => (int) ($row->impressions ?? 0),
            'clicks' => (int) ($row->clicks ?? 0),
        ];
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    public function spendByCampaign(?array $range): Collection
    {
        if (! $this->hasAdSpendTable()) {
            return collect();
        }

        return $this->campaignSpendQuery($range)
            ->select('platform', 'campaign_name')
            ->selectRaw('COALESCE(SUM(spend), 0) as total_spend')
            ->where('campaign_name', '!=', '')
            ->whereNotNull('campaign_name')
            ->groupBy('platform', 'campaign_name')
            ->get();
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    public function spendByKeyword(?array $range): Collection
    {
        if (! $this->hasAdSpendTable()) {
            return collect();
        }

        return $this->keywordSpendQuery($range)
            ->select('keyword')
            ->selectRaw('COALESCE(SUM(impressions), 0) as impressions')
            ->selectRaw('COALESCE(SUM(clicks), 0) as clicks')
            ->groupBy('keyword')
            ->get();
    }

    /**
     * @param  list<string>  $sources
     */
    public function whereSourceIn($query, array $sources)
    {
        if (! $this->hasAttributionField('utm_source') || $sources === []) {
            return $query->whereRaw('0 = 1');
        }

        $placeholders = implode(',', array_fill(0, count($sources), '?'));

        return $query->whereRaw($this->sourceExpression().' in ('.$placeholders.')', $sources);
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function previousRange(?array $range): ?array
    {
        if ($range === null) {
            return null;
        }

        $days = max(1, (int) $range[0]->diffInDays($range[1]) + 1);
        $to = $range[0]->copy()->subDay()->endOfDay();
        $from = $to->copy()->subDays($days - 1)->startOfDay();

        return [$from, $to];
    }

    public function money(float $amount): int|float
    {
        $rounded = round($amount, 2);

        return abs($rounded - (int) round($rounded)) < 0.005
            ? (int) round($rounded)
            : $rounded;
    }

    public function changePercent(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    public function channelMeta(string $source): array
    {
        $meta = self::CHANNELS[$source] ?? [
            'ar' => $source,
            'en' => $source,
            'color' => 'gray',
        ];

        return [
            'source' => $source,
            'label_ar' => $meta['ar'],
            'label_en' => $meta['en'],
            'color' => $meta['color'],
        ];
    }

    public function usersHavePlatform(): bool
    {
        return $this->columnExists('users', 'platform');
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    public function applyDateRange($query, string $column, ?array $range): void
    {
        if ($range === null) {
            return;
        }

        $query->whereBetween($column, [$range[0]->toDateTimeString(), $range[1]->toDateTimeString()]);
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    protected function applySpendRange($query, ?array $range): void
    {
        if ($range === null || ! $this->hasAdSpendTable()) {
            return;
        }

        $query->whereDate('spent_on', '>=', $range[0]->toDateString())
            ->whereDate('spent_on', '<=', $range[1]->toDateString());
    }

    protected function joinUsersForAttribution($query): void
    {
        if (! $this->columnExists('users', 'utm_source')) {
            return;
        }

        $query->leftJoin('users', 'users.id', '=', 'contracts.user_id');
    }

    protected function coalesceField(string $column, string $fallback): string
    {
        $parts = [];
        if ($this->columnExists('contracts', $column)) {
            $parts[] = "NULLIF(contracts.{$column}, '')";
        }
        if ($this->columnExists('users', $column)) {
            $parts[] = "NULLIF(users.{$column}, '')";
        }

        if ($parts === []) {
            return $fallback;
        }

        $parts[] = $fallback;

        return 'COALESCE('.implode(', ', $parts).')';
    }

    protected function tableExists(string $table): bool
    {
        $key = 'table:'.$table;
        if (! array_key_exists($key, $this->schemaCache)) {
            $this->schemaCache[$key] = $this->lookupTable($table);
        }

        return $this->schemaCache[$key];
    }

    protected function columnExists(string $table, string $column): bool
    {
        $key = $table.'.'.$column;
        if (! array_key_exists($key, $this->schemaCache)) {
            $this->schemaCache[$key] = $this->lookupColumn($table, $column);
        }

        return $this->schemaCache[$key];
    }

    protected function lookupTable(string $table): bool
    {
        return AttributionSchema::hasTable($table);
    }

    protected function lookupColumn(string $table, string $column): bool
    {
        return AttributionSchema::hasColumn($table, $column);
    }

    protected function withoutDefaultSelect($query)
    {
        $query->getQuery()->columns = null;

        return $query;
    }
}
