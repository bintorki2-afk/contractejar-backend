<?php

namespace App\Services\Admin;

use App\Models\Contract;
use App\Models\ContractStatusHistory;
use App\Models\Payment;
use App\Models\ReceivedContract;
use App\Services\Orders\OrderAttentionService;
use App\Support\SchemaCache;
use Illuminate\Support\Carbon;

/**
 * تقرير المالك الأسبوعي (دفعة د — ب18): آخر 7 أيام.
 */
class WeeklyOwnerReportService
{
    public function __construct(private readonly OrderAttentionService $attention) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?Carbon $end = null): array
    {
        $end ??= now();
        $start = $end->copy()->subDays(7);

        $orders = Contract::query()->adminListed()->whereBetween('created_at', [$start, $end])
            ->when(SchemaCache::hasColumn('contracts', 'is_synthetic'), fn ($q) => $q->where(fn ($w) => $w->whereNull('is_synthetic')->orWhere('is_synthetic', false)));
        $ordersCount = (clone $orders)->count();
        $paidCount = (clone $orders)->where('is_completed', 1)->count();

        $payments = Payment::query()->where('status', 'success')->whereBetween('created_at', [$start, $end]);
        $gross = round((float) (clone $payments)->sum('amount'), 2);
        $refunded = SchemaCache::hasTable('refunds')
            ? round((float) \App\Models\Refund::query()->where('status', 'succeeded')->whereBetween('created_at', [$start, $end])->sum('amount'), 2)
            : 0.0;

        // التوثيقات خلال الأسبوع: مدة الدفع ← التوثيق.
        $durations = [];
        $notarized = ContractStatusHistory::query()->whereIn('status', ['ejar_authenticated', 'completed'])
            ->whereBetween('created_at', [$start, $end])->orderBy('id')->get(['contract_id', 'created_at'])->unique('contract_id');
        foreach ($notarized as $row) {
            $paidAt = ContractStatusHistory::query()->where('contract_id', $row->contract_id)->whereIn('status', ['paid', 'under_review'])->orderBy('id')->value('created_at');
            if ($paidAt) {
                $durations[(int) $row->contract_id] = Carbon::parse($paidAt)->diffInMinutes(Carbon::parse($row->created_at)) / 60;
            }
        }
        $avg = $durations === [] ? null : round(array_sum($durations) / count($durations), 1);
        $slowestId = $durations === [] ? null : array_search(max($durations), $durations, true);
        $slowest = $slowestId ? ['uuid' => (string) Contract::query()->whereKey($slowestId)->value('uuid'), 'hours' => round($durations[$slowestId], 1)] : null;

        $top = ReceivedContract::query()->whereBetween('created_at', [$start, $end])
            ->selectRaw('employee_id, COUNT(*) as c')->groupBy('employee_id')->orderByDesc('c')->first();
        $topEmployee = $top ? ['name' => (string) (\App\Models\Employee::query()->whereKey($top->employee_id)->value('name') ?? '—'), 'orders' => (int) $top->c] : null;

        $board = $this->attention->board(5);

        // دفعة (هـ) — 2.7: رسوم إضافية / فروقات / استرجاعات / طلبات مرفق ناقص مفتوحة.
        $hasKind = SchemaCache::hasColumn('payments', 'kind');
        $extraQ = $hasKind ? (clone $payments)->where('kind', Payment::KIND_EXTRA_FEE) : null;
        $diffQ = $hasKind ? (clone $payments)->where('kind', Payment::KIND_PRICE_DIFFERENCE) : null;
        $refundsCount = SchemaCache::hasTable('refunds')
            ? \App\Models\Refund::query()->where('status', 'succeeded')->whereBetween('created_at', [$start, $end])->count()
            : 0;
        $openDataRequests = SchemaCache::hasTable('contract_data_requests')
            ? \App\Models\ContractDataRequest::query()->where('status', 'pending')->count()
            : 0;

        return [
            'extra_fees_count' => $extraQ ? (clone $extraQ)->count() : 0,
            'extra_fees_amount' => $extraQ ? round((float) (clone $extraQ)->sum('amount'), 2) : 0.0,
            'price_differences_count' => $diffQ ? (clone $diffQ)->count() : 0,
            'price_differences_amount' => $diffQ ? round((float) (clone $diffQ)->sum('amount'), 2) : 0.0,
            'refunds_count' => $refundsCount,
            'open_data_requests' => $openDataRequests,
            'awaiting_customer' => (int) ($board['counts']['awaiting_customer'] ?? 0),
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'orders' => $ordersCount,
            'paid' => $paidCount,
            'revenue' => round($gross - $refunded, 2),
            'revenue_gross' => $gross,
            'refunded' => $refunded,
            'notarized' => count($durations),
            'avg_notarization_hours' => $avg,
            'slowest_order' => $slowest,
            'top_employee' => $topEmployee,
            'delayed' => (int) $board['counts']['delayed'],
        ];
    }

    /** @param array<string, mixed> $r */
    public function text(array $r): string
    {
        $money = static fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.');

        return implode("\n", array_filter([
            '📊 تقرير «عقد إيجار» الأسبوعي',
            "الفترة: {$r['from']} → {$r['to']}",
            '',
            "🧾 الطلبات: {$r['orders']} (مدفوع {$r['paid']})",
            '💰 الإيراد: '.$money($r['revenue']).' ر.س'.($r['refunded'] > 0 ? ' (بعد استرجاع '.$money($r['refunded']).')' : ''),
            "✅ موثّق هذا الأسبوع: {$r['notarized']}",
            '⏱️ متوسط مدة التوثيق: '.($r['avg_notarization_hours'] !== null ? $r['avg_notarization_hours'].' ساعة' : '—'),
            '🐢 أبطأ طلب: '.($r['slowest_order'] ? '#'.$r['slowest_order']['uuid'].' ('.$r['slowest_order']['hours'].' ساعة)' : '—'),
            '🏆 أفضل موظف: '.($r['top_employee'] ? $r['top_employee']['name'].' ('.$r['top_employee']['orders'].' طلب)' : '—'),
            '⚠️ طلبات متأخرة الآن: '.$r['delayed'],
            // دفعة (هـ)
            '➕ رسوم إضافية: '.($r['extra_fees_count'] ?? 0).' ('.$money($r['extra_fees_amount'] ?? 0).' ر.س) · فروقات: '.($r['price_differences_count'] ?? 0).' ('.$money($r['price_differences_amount'] ?? 0).' ر.س) · استرجاعات: '.($r['refunds_count'] ?? 0).' ('.$money($r['refunded'] ?? 0).' ر.س)',
            '📎 طلبات مرفق ناقص مفتوحة: '.($r['open_data_requests'] ?? 0).(($r['awaiting_customer'] ?? 0) > 0 ? ' (منها '.$r['awaiting_customer'].' بلا رد +24 ساعة)' : ''),
        ], static fn ($l) => $l !== null));
    }
}
