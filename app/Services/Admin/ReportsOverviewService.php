<?php

namespace App\Services\Admin;

use App\Models\Contract;
use App\Models\ContractStatusHistory;
use App\Models\Payment;
use App\Services\Marketing\Tracking\MarketingAttributionQueries;
use App\Support\SchemaCache;
use Illuminate\Support\Carbon;

/**
 * «نظرة عامة» (دفعة د — ب21): 6 أرقام بسيطة من نطاق واحد متسق
 * (طلب = غير محذوف والخطوة ≥ 4، بلا البيانات التجريبية الاصطناعية).
 */
class ReportsOverviewService
{
    public const RANGES = ['today' => 'اليوم', 'week' => 'هذا الأسبوع', 'month' => 'هذا الشهر', 'year' => 'هذه السنة', 'all' => 'كل الفترات'];

    public function __construct(private readonly MarketingAttributionQueries $attribution) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(string $range = 'week'): array
    {
        $range = array_key_exists($range, self::RANGES) ? $range : 'week';
        $window = $this->window($range);
        $now = now();

        $ordersToday = $this->orders([$now->copy()->startOfDay(), $now->copy()->endOfDay()])->count();
        $ordersWeek = $this->orders([$now->copy()->startOfWeek(), $now->copy()->endOfWeek()])->count();
        $ordersInRange = $this->orders($window)->count();

        $paidInRange = $this->orders($window)->where('contracts.is_completed', 1);
        $paidCount = (clone $paidInRange)->count();
        $notarizedIds = $this->notarizedContractIds((clone $paidInRange)->pluck('contracts.id')->all());

        // متابعة دفعة (د) — QA: نفس تعريف «الإيراد» في تقرير الأداء (دفعات ناجحة بتاريخ الدفع ضمن الفترة).
        $revenue = app(ReportsService::class)->revenueSummary($window);
        $gross = round((float) $revenue['total_sales'], 2);
        $refunded = round((float) $revenue['refunds_total'], 2);

        return [
            'range' => $range,
            'range_label' => self::RANGES[$range],
            'ranges' => collect(self::RANGES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'cards' => [
                ['key' => 'orders_today', 'label' => 'طلبات اليوم', 'value' => $ordersToday, 'unit' => 'طلب'],
                ['key' => 'orders_week', 'label' => 'طلبات الأسبوع', 'value' => $ordersWeek, 'unit' => 'طلب'],
                ['key' => 'revenue', 'label' => 'الإيراد', 'value' => $gross, 'unit' => 'ر.س', 'gross' => $gross, 'refunded' => $refunded, 'net' => round($gross - $refunded, 2),
                    // دفعة (هـ) — 2.7
                    'extra_fees' => round((float) ($revenue['extra_fees'] ?? 0), 2), 'price_differences' => round((float) ($revenue['price_differences'] ?? 0), 2),
                    'original' => round((float) ($revenue['original_revenue'] ?? $gross), 2), 'bank_transfers' => round((float) ($revenue['bank_transfers'] ?? 0), 2)],
                ['key' => 'extra_fees', 'label' => 'رسوم إضافية', 'value' => round((float) ($revenue['extra_fees'] ?? 0), 2), 'unit' => 'ر.س', 'count' => (int) ($revenue['extra_fees_count'] ?? 0)],
                ['key' => 'price_differences', 'label' => 'فروقات سعر', 'value' => round((float) ($revenue['price_differences'] ?? 0), 2), 'unit' => 'ر.س', 'count' => (int) ($revenue['price_differences_count'] ?? 0)],
                ['key' => 'refunds', 'label' => 'استرجاعات', 'value' => $refunded, 'unit' => 'ر.س'],
                ['key' => 'net_revenue', 'label' => 'صافي الإيراد', 'value' => round($gross - $refunded, 2), 'unit' => 'ر.س'],
                ['key' => 'avg_notarization_hours', 'label' => 'متوسط مدة التوثيق', 'value' => $this->avgNotarizationHours($notarizedIds), 'unit' => 'ساعة'],
                ['key' => 'completion_rate', 'label' => 'نسبة الإنجاز', 'value' => $paidCount > 0 ? (int) round(count($notarizedIds) / $paidCount * 100) : null, 'unit' => '%'],
                ['key' => 'top_source', 'label' => 'أعلى مصدر', 'value' => $this->topSource($window), 'unit' => null],
            ],
            'orders_in_range' => $ordersInRange,
            'paid_in_range' => $paidCount,
            'notarized_in_range' => count($notarizedIds),
            // دفعة (هـ) — 2.7 (نفس الأرقام كمفاتيح مباشرة)
            'extra_fees' => round((float) ($revenue['extra_fees'] ?? 0), 2),
            'price_differences' => round((float) ($revenue['price_differences'] ?? 0), 2),
            'refunds' => $refunded,
            'net_revenue' => round($gross - $refunded, 2),
            'open_data_requests' => \App\Support\SchemaCache::hasTable('contract_data_requests') ? \App\Models\ContractDataRequest::query()->where('status', 'pending')->count() : 0,
            'pending_charges' => \App\Support\SchemaCache::hasTable('contract_charges') ? ['count' => \App\Models\ContractCharge::query()->where('status', 'pending')->count(), 'amount' => round((float) \App\Models\ContractCharge::query()->where('status', 'pending')->sum('amount'), 2)] : ['count' => 0, 'amount' => 0.0],
            'definitions' => [
                'scope' => 'طلب = غير محذوف والخطوة ≥ 4 (نفس «جميع الطلبات»)، تاريخ الإنشاء ضمن الفترة',
                'revenue' => 'مجموع الدفعات الناجحة بتاريخ الدفع ضمن الفترة (= kpis.revenue في تقرير الأداء)؛ net = بعد الاسترجاع',
                'avg_notarization_hours' => 'من الدفع إلى «توثيق العقد في إيجار» للطلبات الموثّقة في الفترة',
                'completion_rate' => 'الموثّق ÷ المدفوع ضمن الفترة',
                'top_source' => 'أكثر مصدر (utm_source) جلب طلبات في الفترة',
            ],
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /** @param array{0: Carbon, 1: Carbon}|null $window */
    private function orders(?array $window)
    {
        $q = Contract::query()->adminListed()
            ->when(SchemaCache::hasColumn('contracts', 'is_synthetic'), fn ($w) => $w->where(fn ($x) => $x->whereNull('contracts.is_synthetic')->orWhere('contracts.is_synthetic', false)));
        if ($window !== null) {
            $q->whereBetween('contracts.created_at', [$window[0]->toDateTimeString(), $window[1]->toDateTimeString()]);
        }

        return $q;
    }

    /** @return array{0: Carbon, 1: Carbon}|null */
    private function window(string $range): ?array
    {
        $now = now();

        return match ($range) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => null,
        };
    }

    /**
     * @param  list<int>  $paidIds
     * @return list<int>
     */
    private function notarizedContractIds(array $paidIds): array
    {
        if ($paidIds === []) {
            return [];
        }

        return ContractStatusHistory::query()->whereIn('contract_id', $paidIds)
            ->whereIn('status', ['ejar_authenticated', 'completed'])
            ->distinct()->pluck('contract_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param list<int> $ids */
    private function avgNotarizationHours(array $ids): ?float
    {
        $hours = [];
        foreach ($ids as $id) {
            $rows = ContractStatusHistory::query()->where('contract_id', $id)->orderBy('id')->get(['status', 'created_at']);
            $paid = $rows->firstWhere('status', 'paid')?->created_at ?? $rows->firstWhere('status', 'under_review')?->created_at;
            $done = $rows->first(fn ($r) => in_array($r->status, ['ejar_authenticated', 'completed'], true))?->created_at;
            if ($paid && $done) {
                $hours[] = max(0, Carbon::parse($paid)->diffInMinutes(Carbon::parse($done)) / 60);
            }
        }

        return $hours === [] ? null : round(array_sum($hours) / count($hours), 1);
    }

    /** @param array{0: Carbon, 1: Carbon}|null $window @return array{key: string, label: string, orders: int}|null */
    private function topSource(?array $window): ?array
    {
        try {
            $row = $this->attribution->contractBase($window)
                ->selectRaw($this->attribution->sourceExpression().' as source')
                ->selectRaw('COUNT(*) as orders')
                ->groupByRaw($this->attribution->groupBySelectPositions(1))
                ->orderByDesc('orders')
                ->first();
        } catch (\Throwable) {
            return null;
        }
        if ($row === null) {
            return null;
        }
        $key = (string) $row->source;
        $label = MarketingAttributionQueries::CHANNELS[$key]['ar'] ?? (config("ads.utm.sources.{$key}.ar") ?? ($key === 'direct' ? 'مباشر' : $key));

        return ['key' => $key, 'label' => $label, 'orders' => (int) $row->orders];
    }
}
