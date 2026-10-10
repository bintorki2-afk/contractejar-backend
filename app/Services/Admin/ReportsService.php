<?php

namespace App\Services\Admin;

use App\Http\Resources\Admin\V2\Api\Reports\CustomersReportResource;
use App\Http\Resources\Admin\V2\Api\Reports\CustomersKpisResource;
use App\Http\Resources\Admin\V2\Api\Reports\OrdersReportResource;
use App\Http\Resources\Admin\V2\Api\Reports\OrdersKpisResource;
use App\Http\Resources\Admin\V2\Api\Reports\PerformanceConversionLeakageResource;
use App\Http\Resources\Admin\V2\Api\Reports\PerformanceKpisResource;
use App\Http\Resources\Admin\V2\Api\Reports\PerformanceReportResource;
use App\Http\Resources\Admin\V2\Api\Reports\ProfitsKpisResource;
use App\Http\Resources\Admin\V2\Api\Reports\ProfitsReportResource;
use App\Http\Resources\Admin\V2\Api\Reports\ReportCollectedBreakdownResource;
use App\Http\Resources\Admin\V2\Api\Reports\ReportEmployeeStatResource;
use App\Http\Resources\Admin\V2\Api\Reports\ReportLabeledValueResource;
use App\Http\Resources\Admin\V2\Api\Reports\ReportOperationalMetricsResource;
use App\Http\Resources\Admin\V2\Api\Reports\ReportPaymentMethodResource;
use App\Http\Resources\Admin\V2\Api\Reports\ReportProfitSettingsResource;
use App\Http\Resources\Admin\V2\Api\Reports\ReportServiceProfitabilityResource;
use App\Http\Resources\Admin\V2\Api\Reports\ReportServiceRevenueResource;
use App\Http\Resources\Admin\V2\Api\Reports\ReportSourceSummaryResource;
use App\Http\Resources\Admin\V2\Api\Reports\ReportTopCustomerResource;
use App\Http\Resources\Admin\V2\Api\Reports\ReportUnitEconomicsResource;
use App\Http\Resources\Admin\V2\Api\Reports\SalesKpisResource;
use App\Http\Resources\Admin\V2\Api\Reports\SalesReportResource;
use App\Http\Resources\Admin\V2\Api\Reports\SalesSummaryResource;
use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\CouponUsage;
use App\Models\OperatingExpense;
use App\Models\Payment;
use App\Models\RefundableContract;
use App\Models\Setting;
use App\Models\User;
use App\Support\Concerns\ResolvesReportPeriod;
use App\Support\DocFee;
use App\Support\EjarPlatformFee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backing service for the admin Reports page tabs (/admin/reports/*).
 *
 * Marketing (source/UTM/ad-spend) lives in MarketingReportsService and
 * /api/admin/reports/marketing. First-touch UTM is stored on users/contracts;
 * platform spend is synced into ad_spend_dailies.
 */
class ReportsService
{
    use ResolvesReportPeriod;

    public function __construct(
        protected EjarPlatformFeeService $ejarFees,
        protected MoyasarFeeService $moyasarFees,
        protected MessagingCostService $messagingCosts,
    ) {}

    public const PRORATION_MONTH_DAYS = 30;

    public const LOW_MARGIN_PERCENT = 20;

    /**
     * Receive-queue SLA shown on the performance tab ("نسبة الالتزام خلال 15 دقيقة").
     * Distinct from config('employee_kpis.receive_sla_minutes'), which scores individual employees.
     */
    public const RECEIVE_SLA_MINUTES = 15;

    public const RECEIVE_LATE_MINUTES = 30;

    /**
     * @param  array{range: array{0: Carbon, 1: Carbon}|null}  $filter
     */
    public function orders(array $filter, ?string $contractType, ?int $employeeId): OrdersReportResource
    {
        $range = $filter['range'];
        $base = $this->ordersBaseQuery($range, $contractType, $employeeId);

        $total = (clone $base)->count();
        $new = (clone $base)->where('contract_status_id', ContractStatus::NEW_ID)->count();
        $paid = (clone $base)->where('is_completed', 1)->count();
        $draft = (clone $base)->where('is_draft', true)->count();
        $incomplete = (clone $base)->where('is_completed', 0)->count();
        // متابعة دفعة (د): «مسترجعة» = نفس التعريف في كل التقارير (حالة مسترجع أو استرجاع Moyasar ناجح).
        $returned = $this->refundedOrdersQuery($base)->count();
        $canceled = $this->canceledContractsQuery($range, $contractType, $employeeId)->count();

        return new OrdersReportResource([
            'kpis' => new OrdersKpisResource([
                'total' => $total,
                'new' => $new,
                'paid' => $paid,
                'draft' => $draft,
                'incomplete' => $incomplete,
                'canceled' => $canceled,
                'returned' => $returned,
                'refunded_orders' => $returned,
                'avg_completion_minutes' => $this->avgCompletionMinutes($range, $contractType, $employeeId),
            ]),
            'by_employee' => ReportEmployeeStatResource::collection(
                $this->ordersByEmployee($range, $contractType)
            ),
            'by_contract_type' => ReportLabeledValueResource::collection(
                $this->ordersByContractType($range, $employeeId)
            ),
            'by_stage' => ReportLabeledValueResource::collection(
                $this->statusBreakdown($range, $contractType, $employeeId)
            ),
        ]);
    }

    /**
     * @param  array{range: array{0: Carbon, 1: Carbon}|null}  $filter
     */
    public function sales(array $filter, ?string $contractType, ?int $employeeId): SalesReportResource
    {
        $range = $filter['range'];
        $totals = $this->salesTotals($range, $contractType, $employeeId);
        $revenueBreakdown = $this->revenueByTypeAndDuration($range, $employeeId, $contractType);

        return new SalesReportResource([
            'kpis' => new SalesKpisResource([
                'total_sales' => $totals['total_sales'],
                'payments_count' => $totals['payments_count'],
                'avg_order_value' => $totals['payments_count'] > 0
                    ? $this->moneyValue($totals['total_sales'] / $totals['payments_count'])
                    : 0,
                'discounts_used' => $totals['discounts_total'],
                'refunds' => $totals['refunds_total'],
                'net_revenue' => $totals['net_revenue'],
                // دفعة (هـ)
                'extra_fees' => $totals['extra_fees'],
                'extra_fees_count' => $totals['extra_fees_count'],
                'price_differences' => $totals['price_differences'],
                'price_differences_count' => $totals['price_differences_count'],
                'original_revenue' => $totals['original_revenue'],
                'bank_transfers' => $totals['bank_transfers'],
                'bank_transfers_count' => $totals['bank_transfers_count'],
                'lessor_change_sales' => $totals['lessor_change_sales'] ?? 0,
                'lessor_change_count' => $totals['lessor_change_count'] ?? 0,
            ]),
            'by_period' => ReportLabeledValueResource::collection($this->salesByQuickPeriod()),
            'daily' => ReportLabeledValueResource::collection(
                $this->salesDaily($range, $contractType, $employeeId)
            ),
            'revenue_by_contract_type' => ReportLabeledValueResource::collection(
                $revenueBreakdown['by_type']
            ),
            'revenue_by_duration' => ReportLabeledValueResource::collection(
                $revenueBreakdown['by_duration']
            ),
            'summary' => new SalesSummaryResource([
                'discounts_granted' => $totals['discounts_total'],
                'discounted_orders_count' => $totals['discounted_orders_count'],
                'refunds_total' => $totals['refunds_total'],
                'refund_rate_percent' => $totals['total_sales'] > 0
                    ? (int) round(($totals['refunds_total'] / $totals['total_sales']) * 100)
                    : 0,
                'net_revenue_after_refunds' => $totals['net_revenue'],
                'extra_fees' => $totals['extra_fees'],
                'price_differences' => $totals['price_differences'],
                'refunds' => $totals['refunds'],
                'net_revenue' => $totals['net_revenue'],
            ]),
        ]);
    }

    /**
     * @param  array{range: array{0: Carbon, 1: Carbon}|null}  $filter
     */
    public function profits(array $filter, bool $includeSalaries): ProfitsReportResource
    {
        $range = $filter['range'];
        $pnl = $this->buildPnl($range, null, null, $includeSalaries);
        $figures = $pnl['figures'];
        $totals = $figures['sales_totals'];
        $settings = $figures['settings'];

        $paidCount = $figures['paid_count'];
        $netRevenue = (float) $totals['net_revenue'];
        $meterCollected = $this->moneyValue(min($figures['meter_units'] * $figures['meter_fee'], $netRevenue));
        $documentationCollected = $this->moneyValue(max(0, $netRevenue - $meterCollected));

        $operatingProfit = round($netRevenue - $figures['ejar_fees'] - $figures['gateway_fee'], 2);
        $operatingProfitPerContract = $paidCount > 0 ? $this->moneyValue($operatingProfit / $paidCount) : 0;
        $monthlyFixed = $figures['monthly_ads'] + $figures['monthly_opex']
            + ($includeSalaries ? $figures['monthly_salaries'] : 0);
        $breakEven = $operatingProfitPerContract > 0
            ? (int) ceil($monthlyFixed / $operatingProfitPerContract)
            : 0;
        $cac = $paidCount > 0 ? $this->moneyValue($figures['ad_spend'] / $paidCount) : 0;

        return new ProfitsReportResource([
            'kpis' => new ProfitsKpisResource([
                'customer_income' => $this->moneyValue($totals['total_sales']),
                'gross_profit' => $this->moneyValue($figures['gross_profit']),
                'net_profit' => $this->moneyValue($figures['net_profit']),
                'margin_percent' => $totals['total_sales'] > 0
                    ? (int) round(($figures['net_profit'] / $totals['total_sales']) * 100)
                    : 0,
                'profit_per_order' => $paidCount > 0 ? $this->moneyValue($figures['net_profit'] / $paidCount) : 0,
                'ad_spend' => $this->moneyValue($figures['ad_spend']),
                'ejar_platform_fees' => $this->moneyValue($figures['ejar_fees']),
                'gateway_fee' => $this->moneyValue($figures['gateway_fee']),
                'messaging_cost' => $this->moneyValue($figures['messaging_cost']),
                'salaries_included' => $includeSalaries,
                'paid_contracts_count' => $paidCount,
                'operating_profit_per_contract' => $operatingProfitPerContract,
                'monthly_break_even_contracts' => $breakEven,
                'cac' => $cac,
                'proration_days' => $figures['proration_days'],
                'proration_month_days' => self::PRORATION_MONTH_DAYS,
            ]),
            'collected_breakdown' => new ReportCollectedBreakdownResource([
                'documentation' => $documentationCollected,
                'meter_transfers' => $meterCollected,
                'contracts_count' => $paidCount,
                'meter_units' => $figures['meter_units'],
            ]),
            'service_revenue' => ReportServiceRevenueResource::collection(
                $this->revenueByTypeAndDuration($range, null)['services']
            ),
            'service_profitability' => ReportServiceProfitabilityResource::collection(
                $this->serviceProfitability($settings)
            ),
            'unit_economics' => ReportUnitEconomicsResource::collection(
                $this->catalogUnitEconomics($settings)
            ),
            'source_summary' => new ReportSourceSummaryResource(
                $this->sourceSummary(
                    $figures['paid_contracts'],
                    $totals,
                    $figures['meter_units'],
                    $figures['meter_fee']
                )
            ),
            'pnl' => ReportLabeledValueResource::collection($pnl['lines']),
        ]);
    }

    public function profitSettings(bool $includeSalaries): ReportProfitSettingsResource
    {
        $settings = Setting::query()->first() ?? Setting::query()->create([]);

        return new ReportProfitSettingsResource([
            'settings' => $settings,
            'moyasar' => $this->moyasarFees->rates($settings),
            'meter_transfer_fee' => $this->meterTransferFee($settings),
            'include_salaries' => $includeSalaries,
            'proration_month_days' => self::PRORATION_MONTH_DAYS,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProfitSettings(array $data, bool $canEditSalaries): ReportProfitSettingsResource
    {
        $settings = Setting::query()->first() ?? Setting::query()->create([]);

        $update = array_intersect_key($data, array_flip([
            'moyasar_fee_percent',
            'moyasar_mada_percent',
            'moyasar_credit_percent',
            'moyasar_fixed_fee',
            'operating_budget',
            'marketing_budget',
            'meter_transfer_fee',
        ]));

        if (array_key_exists('moyasar_credit_percent', $data)) {
            $update['moyasar_credit_percent'] = $data['moyasar_credit_percent'];
            $update['moyasar_fee_percent'] = $data['moyasar_credit_percent'];
        } elseif (array_key_exists('moyasar_fee_percent', $data)) {
            $update['moyasar_credit_percent'] = $data['moyasar_fee_percent'];
        }

        if ($canEditSalaries && array_key_exists('monthly_salaries', $data)) {
            $update['monthly_salaries'] = $data['monthly_salaries'];
        }

        if (array_key_exists('meter_transfer_fee', $update) && ! Schema::hasColumn('settings', 'meter_transfer_fee')) {
            unset($update['meter_transfer_fee']);
        }

        $settings->update($update);

        return $this->profitSettings($canEditSalaries);
    }

    /**
     * @param  array{range: array{0: Carbon, 1: Carbon}|null}  $filter
     */
    public function customers(array $filter, ?string $contractType, ?int $employeeId): CustomersReportResource
    {
        $range = $filter['range'];

        $periodContracts = $this->ordersBaseQuery($range, $contractType, $employeeId);

        $customerIds = (clone $periodContracts)->pluck('user_id')->filter()->unique();
        $active = $customerIds->count();

        // دفعة (د) — ب6: إجمالي العملاء والعملاء الجدد بنفس نطاق صفحة العملاء (User::customers()).
        $total = \App\Modules\Users\Models\User::query()->customers()->count();
        $newQuery = \App\Modules\Users\Models\User::query()->customers();
        if ($range !== null) {
            $newQuery->whereBetween('users.created_at', [$range[0]->toDateTimeString(), $range[1]->toDateTimeString()]);
        }
        $newCustomers = $newQuery->count();

        // العملاء الذين طلبوا في الفترة وكان أول طلب لهم قبلها = عائدون.
        $returning = 0;
        if ($customerIds->isNotEmpty() && $range !== null) {
            $returning = Contract::query()
                ->whereIn('user_id', $customerIds)
                ->notDeleted()
                ->groupBy('user_id')
                ->selectRaw('user_id, MIN(created_at) as first_created_at')
                ->pluck('first_created_at', 'user_id')
                ->filter(fn ($first) => Carbon::parse($first)->lt($range[0]))
                ->count();
        }

        $incompleteCustomers = (clone $periodContracts)->where('is_completed', 0)->pluck('user_id')->filter()->unique()->count();
        $totalContracts = (clone $periodContracts)->count();

        $topCustomers = $this->topCustomers($range, $contractType, $employeeId);

        return new CustomersReportResource([
            'kpis' => new CustomersKpisResource([
                'total' => $total,
                'new' => $newCustomers,
                'active' => $active,
                'returning' => $returning,
                'avg_contracts_per_customer' => $active > 0 ? round($totalContracts / $active, 1) : 0,
                'incomplete' => $incompleteCustomers,
            ]),
            'segments' => ReportLabeledValueResource::collection([
                ['label' => 'عملاء جدد', 'value' => $newCustomers],
                ['label' => 'عملاء عائدون', 'value' => $returning],
            ]),
            'top_customers' => ReportTopCustomerResource::collection($topCustomers),
        ]);
    }

    /**
     * @param  array{range: array{0: Carbon, 1: Carbon}|null}  $filter
     */
    public function performance(
        array $filter,
        ?string $contractType = null,
        ?int $employeeId = null,
        bool $includeSalaries = false
    ): PerformanceReportResource {
        $range = $filter['range'];
        $base = $this->ordersBaseQuery($range, $contractType, $employeeId);
        $doneIds = $this->doneStatusIds();
        $notOpenIds = $this->notOpenStatusIds();

        $startedCount = $this->startedOrdersQuery($range, $contractType, $employeeId)->count();
        $totalCount = (clone $base)->count();
        $paidCount = (clone $base)->where('is_completed', 1)->count();
        $documentedCount = $doneIds === [] ? 0 : (clone $base)->whereIn('contract_status_id', $doneIds)->count();
        $draftCount = $this->reachedDraftCount($range, $contractType, $employeeId, $doneIds);
        $receivedCount = (clone $base)->whereHas('receivedContract')->count();
        $refundRequestsConfirmed = $this->refundedCount($range);
        $refundedCount = $this->refundedOrdersQuery($base)->count();
        $canceledCount = $this->canceledContractsQuery($range, $contractType, $employeeId)->count();
        $activeCount = (clone $base)
            ->when($notOpenIds !== [], fn ($q) => $q->where(function ($sq) use ($notOpenIds) {
                $sq->whereNull('contract_status_id')->orWhereNotIn('contract_status_id', $notOpenIds);
            }))
            ->count();

        $salesTotals = $this->salesTotals($range, $contractType, $employeeId);
        $revenue = $salesTotals['total_sales'];
        $delayed = $this->delayedOpenContractsCount($range);

        $funnel = $this->conversionFunnel([
            'بداية طلب' => $startedCount,
            'طلب مكتمل البيانات' => $totalCount,
            'مسودة عقد' => $draftCount,
            'مدفوع' => $paidCount,
            'موثّق' => $documentedCount,
        ]);

        $leakageCount = max(0, $startedCount - $paidCount);
        $pnl = $this->buildPnl($range, $contractType, $employeeId, $includeSalaries);
        $figures = $pnl['figures'];

        return new PerformanceReportResource([
            'period_label' => $filter['label_ar'] ?? null,
            'kpis' => new PerformanceKpisResource([
                'total_count' => $totalCount,
                'total' => $totalCount,
                'documented_count' => $documentedCount,
                'working_count' => $activeCount,
                'active_count' => $activeCount,
                'canceled_count' => $canceledCount,
                'refunded_count' => $refundedCount,
                'refunded_orders' => $refundedCount,
                'refund_requests_confirmed' => $refundRequestsConfirmed,
                'revenue' => $this->moneyValue($revenue),
                // دفعة (هـ) — 2.7
                'extra_fees' => $salesTotals['extra_fees'],
                'price_differences' => $salesTotals['price_differences'],
                'refunds' => $salesTotals['refunds'],
                'net_revenue' => $salesTotals['net_revenue'],
                'paid' => $paidCount,
                'delayed_count' => $delayed,
            ]),
            'conversion_funnel' => ReportLabeledValueResource::collection($funnel),
            'conversion_leakage' => new PerformanceConversionLeakageResource([
                'count' => $leakageCount,
                'percent' => $this->percentOf($leakageCount, $startedCount),
            ]),
            // دفعة (د) — ب6: أرقام القمع الصريحة (التسرّب = البداية − المكتمل الدفع؛ مثال 16 − 3 = 13 = 81%).
            'funnel_summary' => [
                'started' => $startedCount,
                'completed' => $paidCount,
                'drop_off' => $leakageCount,
                'drop_off_percent' => $this->percentOf($leakageCount, $startedCount),
                'cancelled' => $canceledCount,
                'cancellation_rate' => $this->percentOf($canceledCount, $startedCount),
                'definitions' => [
                    'started' => 'كل طلب بدأه العميل في الفترة (غير محذوف، أي خطوة)',
                    'completed' => 'الطلبات المدفوعة (is_completed = 1)',
                    'drop_off' => 'started − completed',
                    'cancellation_rate' => 'cancelled ÷ started',
                ],
            ],
            'conversion_rates' => ReportLabeledValueResource::collection([
                [
                    'label' => 'نسبة عدم الإكمال (تسرّب)',
                    'value' => $this->percentOf($leakageCount, $startedCount),
                    'tone' => 'gold',
                ],
                [
                    'label' => 'تحويل المسودة إلى دفع',
                    'value' => $this->percentOf($paidCount, $draftCount),
                    'tone' => 'green',
                ],
                [
                    'label' => 'نسبة استلام الطلبات',
                    'value' => $this->percentOf($receivedCount, $totalCount),
                    'tone' => null,
                ],
                [
                    'label' => 'نسبة التوثيق',
                    'value' => $this->percentOf($documentedCount, $totalCount),
                    'tone' => 'green',
                ],
                [
                    'label' => 'نسبة الإلغاء',
                    'value' => $this->percentOf($canceledCount, $startedCount),
                    'tone' => 'red',
                ],
                [
                    'label' => 'نسبة الاسترجاع',
                    'value' => $this->percentOf($refundedCount, $paidCount),
                    'tone' => 'red',
                ],
            ]),
            'daily_orders' => ReportLabeledValueResource::collection(
                $this->dailyOrders($range, $contractType, $employeeId)
            ),
            'orders_by_status' => ReportLabeledValueResource::collection(
                array_values(array_filter(
                    $this->statusBreakdown($range, $contractType, $employeeId),
                    fn (array $row) => $row['value'] > 0
                ))
            ),
            'by_contract_type' => ReportLabeledValueResource::collection(
                $this->performanceByContractType($range, $contractType, $employeeId)
            ),
            'by_employee' => ReportEmployeeStatResource::collection(
                $this->performanceByEmployee($range, $contractType, $employeeId)
            ),
            'operational_metrics' => new ReportOperationalMetricsResource(
                $this->operationalMetrics($range, $contractType, $employeeId, $totalCount, $delayed)
            ),
            'revenue_by_payment_method' => ReportPaymentMethodResource::collection(
                $this->revenueByPaymentMethod($range, $contractType, $employeeId)
            ),
            'pnl' => ReportLabeledValueResource::collection($pnl['lines']),
            'unit_economics' => ReportUnitEconomicsResource::collection(
                $this->catalogUnitEconomics($figures['settings'])
            ),
            'unit_economics_note' => 'الأرقام محسوبة من أسعار الخدمات الحالية ورسوم منصة إيجار ونسب موياسر في إعدادات الأرباح.',
            'financial_summary' => ReportLabeledValueResource::collection(
                $this->financialSummary($figures)
            ),
            'by_document_type' => ReportLabeledValueResource::collection(
                $this->performanceByDocumentType($range, $contractType, $employeeId)
            ),
            'correction_errors' => ReportLabeledValueResource::collection([]),
            'refund_requests_by_status' => ReportLabeledValueResource::collection(
                $this->refundRequestsByStatus($range)
            ),
            'refund_requests_total' => $this->moneyValue($this->refundRequestsAmount($range)),
        ]);
    }

    // ------------------------------------------------------------------
    // Shared query builders
    // ------------------------------------------------------------------

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    private function ordersBaseQuery(?array $range, ?string $contractType, ?int $employeeId)
    {
        return $this->startedOrdersQuery($range, $contractType, $employeeId)->reachedAdminOrderStep();
    }

    /**
     * Every order the customer began, including the early steps that never reach the
     * admin queue — the top of the conversion funnel.
     *
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    private function startedOrdersQuery(?array $range, ?string $contractType, ?int $employeeId)
    {
        $query = Contract::query()->notDeleted()->notSynthetic();
        $this->applyDateRange($query, 'created_at', $range);

        if ($contractType !== null) {
            $query->where('contract_type', $contractType);
        }

        if ($employeeId !== null) {
            $query->whereHas('receivedContract', fn ($q) => $q->where('employee_id', $employeeId));
        }

        return $query;
    }

    /**
     * طلبات مسترجعة: حالتها «مسترجع» أو لها استرجاع ناجح عبر Moyasar (ضمن نفس النطاق).
     */
    private function refundedOrdersQuery($base)
    {
        $refundedIds = ContractStatus::idsFor([ContractStatus::KEY_REFUNDED]) ?: [-1];
        $hasRefunds = Schema::hasTable('refunds');

        return (clone $base)->where(function ($q) use ($refundedIds, $hasRefunds) {
            $q->whereIn('contract_status_id', $refundedIds);
            if ($hasRefunds) {
                $q->orWhereIn('uuid', \App\Models\Refund::query()->where('status', 'succeeded')->select('contract_uuid'));
            }
        });
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    private function canceledContractsQuery(?array $range, ?string $contractType, ?int $employeeId)
    {
        // دفعة (د) — ب6: «ملغى» = حالة الطلب cancelled (لا الطلبات المحذوفة is_delete).
        $query = Contract::query()->notDeleted()->notSynthetic()
            ->whereIn('contract_status_id', ContractStatus::idsFor([ContractStatus::KEY_CANCELLED]) ?: [-1]);
        $this->applyDateRange($query, 'created_at', $range);

        if ($contractType !== null) {
            $query->where('contract_type', $contractType);
        }

        if ($employeeId !== null) {
            $query->whereHas('receivedContract', fn ($q) => $q->where('employee_id', $employeeId));
        }

        return $query;
    }

    private function applyDateRange($query, string $column, ?array $range): void
    {
        if ($range === null) {
            return;
        }

        $query->whereBetween($column, [$range[0]->toDateTimeString(), $range[1]->toDateTimeString()]);
    }

    /**
     * @return list<int>
     */
    private function doneStatusIds(): array
    {
        return ContractStatus::query()
            ->where(function ($q) {
                $q->whereKey(ContractStatus::idsFor([ContractStatus::KEY_WAITING_SUPERVISOR, ContractStatus::KEY_COMPLETED]) ?: [-1])
                    ->orWhere('name', 'مكتمل')
                    ->orWhere('name', 'like', '%بانتظار المشرف%');
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function notOpenStatusIds(): array
    {
        $ids = $this->doneStatusIds();

        $closed = array_values(array_unique([
            ...ContractStatus::idsFor([ContractStatus::KEY_REFUNDED, ContractStatus::KEY_CANCELLED, ContractStatus::KEY_COMPLETED]),
            ...ContractStatus::query()
                ->whereIn('name', ['ملغى', 'مكتمل', 'مسترجع', 'استرجاع'])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        ]));

        return array_values(array_unique([...$ids, ...$closed]));
    }

    // ------------------------------------------------------------------
    // Orders tab helpers
    // ------------------------------------------------------------------

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    private function avgCompletionMinutes(?array $range, ?string $contractType, ?int $employeeId): ?int
    {
        $query = $this->ordersBaseQuery($range, $contractType, $employeeId)->where('is_completed', 1);

        $rows = $query
            ->select(['contracts.id', 'contracts.uuid', 'contracts.created_at'])
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $uuids = $rows->pluck('uuid')->all();
        $firstPaymentAt = Payment::query()
            ->successful()
            ->where(function ($q) use ($uuids) {
                foreach ($uuids as $uuid) {
                    $q->orWhere('contract_uuid', $uuid)->orWhere('contract_uuid', 'like', $uuid.'-%');
                }
            })
            ->orderBy('created_at')
            ->get(['contract_uuid', 'created_at'])
            ->groupBy(fn ($p) => (string) preg_replace('/-.*$/', '', (string) $p->contract_uuid))
            ->map(fn ($group) => $group->first()->created_at);

        $minutes = [];
        foreach ($rows as $contract) {
            $paidAt = $firstPaymentAt->get((string) $contract->uuid);
            if ($paidAt === null || $contract->created_at === null) {
                continue;
            }
            $minutes[] = Carbon::parse($contract->created_at)->diffInMinutes(Carbon::parse($paidAt));
        }

        if ($minutes === []) {
            return null;
        }

        return (int) round(array_sum($minutes) / count($minutes));
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return list<array{employee_id: int, label: string, value: int}>
     */
    private function ordersByEmployee(?array $range, ?string $contractType): array
    {
        $query = DB::table('received_contracts')
            ->join('contracts', 'contracts.id', '=', 'received_contracts.contract_id')
            ->join('employees', 'employees.id', '=', 'received_contracts.employee_id')
            ->where('contracts.is_delete', 0);

        $this->applyDateRange($query, 'received_contracts.created_at', $range);

        if ($contractType !== null) {
            $query->where('contracts.contract_type', $contractType);
        }

        return $query
            ->groupBy('received_contracts.employee_id', 'employees.name')
            ->selectRaw('received_contracts.employee_id, employees.name as label, COUNT(*) as aggregate')
            ->orderByDesc('aggregate')
            ->get()
            ->map(fn ($row) => [
                'employee_id' => (int) $row->employee_id,
                'label' => $row->label,
                'value' => (int) $row->aggregate,
            ])
            ->all();
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return list<array{label: string, value: int}>
     */
    private function ordersByContractType(?array $range, ?int $employeeId): array
    {
        $rows = $this->ordersBaseQuery($range, null, $employeeId)
            ->groupBy('contract_type')
            ->selectRaw('contract_type, COUNT(*) as aggregate')
            ->pluck('aggregate', 'contract_type');

        return collect(Contract::CONTRACT_TYPES)
            ->map(fn (string $type) => [
                'label' => Contract::contractTypeLabel($type),
                'value' => (int) ($rows[$type] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return list<array{stage: string, label: string, value: int}>
     */
    private function statusBreakdown(?array $range, ?string $contractType, ?int $employeeId): array
    {
        $rows = $this->ordersBaseQuery($range, $contractType, $employeeId)
            ->whereNotNull('contract_status_id')
            ->groupBy('contract_status_id')
            ->selectRaw('contract_status_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'contract_status_id');

        return ContractStatus::query()
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (ContractStatus $status) => [
                'stage' => 'status_'.$status->id,
                'label' => $status->name,
                'value' => (int) ($rows[$status->id] ?? 0),
            ])
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    // Sales / revenue helpers
    // ------------------------------------------------------------------

    /**
     * Successful payments joined to their contract, so revenue can be grouped by any
     * contract column. Payments carry the contract uuid with an optional "-suffix".
     *
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    private function paidContractsJoinQuery(?array $range, ?string $contractType = null, ?int $employeeId = null)
    {
        $query = Payment::query()
            ->join('contracts', function ($join) {
                $uuidColumn = DB::connection()->getDriverName() === 'sqlite'
                    ? 'CAST(contracts.uuid AS TEXT)'
                    : 'CAST(contracts.uuid AS CHAR)';

                $join->on(DB::raw($uuidColumn), '=', DB::raw($this->paymentContractUuidExpression()));
            })
            ->where('payments.status', 'success')
            ->where('contracts.is_delete', 0);

        $this->applyPaymentDateRange($query, $range);

        if ($contractType !== null) {
            $query->where('contracts.contract_type', $contractType);
        }

        if ($employeeId !== null) {
            $query->whereExists(function ($sub) use ($employeeId) {
                $sub->selectRaw('1')
                    ->from('received_contracts')
                    ->whereColumn('received_contracts.contract_id', 'contracts.id')
                    ->where('received_contracts.employee_id', $employeeId);
            });
        }

        return $query;
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return array{total_sales: float, payments_count: int, discounts_total: float, discounted_orders_count: int, refunds_total: float, net_revenue: float}
     */
    /**
     * متابعة دفعة (د) — QA: تعريف «الإيراد» الوحيد لكل الشاشات (الأداء/المبيعات/نظرة عامة):
     * مجموع الدفعات الناجحة بتاريخ الدفع ضمن الفترة (أساس نقدي)، والصافي بعد الاسترجاع.
     *
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return array{total_sales: float|int, payments_count: int, refunds_total: float|int, net_revenue: float|int}
     */
    public function revenueSummary(?array $range): array
    {
        $t = $this->salesTotals($range, null, null);

        return [
            'total_sales' => $t['total_sales'],
            'payments_count' => $t['payments_count'],
            'refunds_total' => $t['refunds_total'],
            'net_revenue' => $t['net_revenue'],
            // دفعة (هـ)
            'extra_fees' => $t['extra_fees'],
            'extra_fees_count' => $t['extra_fees_count'],
            'price_differences' => $t['price_differences'],
            'price_differences_count' => $t['price_differences_count'],
            'refunds' => $t['refunds'],
            'original_revenue' => $t['original_revenue'],
            'bank_transfers' => $t['bank_transfers'],
            'bank_transfers_count' => $t['bank_transfers_count'],
        ];
    }

    private function salesTotals(?array $range, ?string $contractType, ?int $employeeId): array
    {
        $paymentsQuery = Payment::query()->successful();
        $this->applyPaymentDateRange($paymentsQuery, $range);

        if ($contractType !== null || $employeeId !== null) {
            $paymentsQuery->whereIn('contract_uuid', $this->filteredContractUuids($range, $contractType, $employeeId));
        }

        $totalSales = (float) (clone $paymentsQuery)->sum('amount');
        $paymentsCount = (clone $paymentsQuery)->count();

        $discounts = $this->couponDiscounts($range);
        $refunds = $this->refundsAmount($range);

        // دفعة (هـ) — 2.7: الرسوم الإضافية وفروقات السعر (دفعات ناجحة بنوعها) منفصلة عن الإيراد الأصلي.
        $extraFees = $this->paymentsByKind($paymentsQuery, \App\Models\Payment::KIND_EXTRA_FEE);
        $priceDifferences = $this->paymentsByKind($paymentsQuery, \App\Models\Payment::KIND_PRICE_DIFFERENCE);
        $bankTransfers = \App\Support\SchemaCache::hasColumn('payments', 'kind')
            ? ['count' => (clone $paymentsQuery)->where('payment_method', \App\Models\Payment::METHOD_BANK_TRANSFER)->count(), 'amount' => $this->moneyValue((float) (clone $paymentsQuery)->where('payment_method', \App\Models\Payment::METHOD_BANK_TRANSFER)->sum('amount'))]
            : ['count' => 0, 'amount' => 0];

        return [
            'total_sales' => $this->moneyValue($totalSales),
            'payments_count' => $paymentsCount,
            'discounts_total' => $discounts['total'],
            'discounted_orders_count' => $discounts['orders_count'],
            'refunds_total' => $refunds,
            'net_revenue' => $this->moneyValue($totalSales - $refunds),
            'extra_fees' => $extraFees['amount'],
            'extra_fees_count' => $extraFees['count'],
            'price_differences' => $priceDifferences['amount'],
            'price_differences_count' => $priceDifferences['count'],
            'refunds' => $refunds,
            'original_revenue' => $this->moneyValue($totalSales - $extraFees['amount'] - $priceDifferences['amount']),
            'bank_transfers' => $bankTransfers['amount'],
            'bank_transfers_count' => $bankTransfers['count'],
            // QA-F PROPS-24: دفعات «تغيير المؤجر» داخل total_sales — بند مستقل للشفافية.
            ...$this->lessorChangeSales($paymentsQuery),
        ];
    }

    /** @return array{lessor_change_sales: int|float, lessor_change_count: int} */
    private function lessorChangeSales($paymentsQuery): array
    {
        try {
            if (! \App\Support\SchemaCache::hasTable('lessor_change_requests')) {
                return ['lessor_change_sales' => 0, 'lessor_change_count' => 0];
            }
            $q = (clone $paymentsQuery)->whereIn('contract_uuid', \App\Models\LessorChangeRequest::query()->select('uuid'));

            return ['lessor_change_sales' => $this->moneyValue((float) (clone $q)->sum('amount')), 'lessor_change_count' => (clone $q)->count()];
        } catch (\Throwable) {
            return ['lessor_change_sales' => 0, 'lessor_change_count' => 0];
        }
    }

    /**
     * @return array{count: int, amount: int|float}
     */
    private function paymentsByKind($paymentsQuery, string $kind): array
    {
        if (! \App\Support\SchemaCache::hasColumn('payments', 'kind')) {
            return ['count' => 0, 'amount' => 0];
        }
        $q = (clone $paymentsQuery)->where('kind', $kind);

        return ['count' => (clone $q)->count(), 'amount' => $this->moneyValue((float) (clone $q)->sum('amount'))];
    }

    /**
     * Contract uuids (with any "-suffix" payment variants) matching contract_type/employee filters, for a period.
     *
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return list<string>
     */
    private function filteredContractUuids(?array $range, ?string $contractType, ?int $employeeId): array
    {
        return $this->ordersBaseQuery($range, $contractType, $employeeId)
            ->pluck('uuid')
            ->map(fn ($uuid) => (string) $uuid)
            ->all();
    }

    private function applyPaymentDateRange($query, ?array $range): void
    {
        if ($range === null) {
            return;
        }

        // دفعة (هـ): نهاية اليوم بالوقت حتى تُحتسب الصفوف المخزّنة بتاريخ ووقت (sqlite محلياً) في اليوم الأخير.
        $query->whereBetween('payment_date', [$range[0]->toDateString(), $range[1]->toDateString().' 23:59:59']);
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return array{total: float, orders_count: int}
     */
    private function couponDiscounts(?array $range): array
    {
        $query = CouponUsage::query()->with('coupon');
        $this->applyDateRange($query, 'used_at', $range);
        $usages = $query->get();

        if ($usages->isEmpty()) {
            return ['total' => 0.0, 'orders_count' => 0];
        }

        $uuids = $usages->pluck('contract_uuid')->filter()->unique()->values()->all();
        $amountsByUuid = Payment::query()
            ->successful()
            ->where(function ($q) use ($uuids) {
                foreach ($uuids as $uuid) {
                    $q->orWhere('contract_uuid', $uuid)->orWhere('contract_uuid', 'like', $uuid.'-%');
                }
            })
            ->get(['contract_uuid', 'amount'])
            ->groupBy(fn ($p) => (string) preg_replace('/-.*$/', '', (string) $p->contract_uuid))
            ->map(fn ($group) => (float) $group->sum('amount'));

        $total = 0.0;
        $contractsWithDiscount = collect();

        foreach ($usages as $usage) {
            $coupon = $usage->coupon;
            if ($coupon === null || $usage->contract_uuid === null) {
                continue;
            }

            $paidAmount = (float) ($amountsByUuid[(string) $usage->contract_uuid] ?? 0);

            if ($coupon->type_coupon === 'ratio') {
                // paidAmount is the post-discount price; back out the discount from the ratio.
                $ratio = (float) $coupon->value_coupon;
                $discount = $ratio < 100 ? round(($paidAmount / (1 - $ratio / 100)) - $paidAmount, 2) : $paidAmount;
            } else {
                $discount = (float) $coupon->value_coupon;
            }

            $total += $discount;
            $contractsWithDiscount->push((string) $usage->contract_uuid);
        }

        return [
            'total' => $this->moneyValue($total),
            'orders_count' => $contractsWithDiscount->unique()->count(),
        ];
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    /** مجموع طلبات الاسترجاع المعتمدة (قسم «طلبات الاسترجاع» فقط — ليس مالاً خرج فعلاً). */
    private function refundRequestsAmount(?array $range): float
    {
        $query = RefundableContract::query()->where('admin_confirmed', true);
        $this->applyDateRange($query, 'created_at', $range);

        return $this->moneyValue((float) $query->sum('refund_amount'));
    }

    private function refundsAmount(?array $range): float
    {
        // QA-F C3: المسترجع = ما نُفّذ فعلاً فقط (جدول refunds بحالة «تم الاسترجاع»)،
        // لا طلبات الاسترجاع «المعتمدة» غير المنفّذة (كانت تطرح 22,516 ر.س وهمية من الإيراد).
        $total = 0.0;
        $refundedContractIds = [];
        if (Schema::hasTable('refunds')) {
            $q = \App\Models\Refund::query()->where('status', \App\Models\Refund::STATUS_SUCCEEDED);
            $this->applyDateRange($q, 'created_at', $range);
            $total += (float) (clone $q)->sum('amount');
            $refundedContractIds = (clone $q)->whereNotNull('contract_id')->pluck('contract_id')->unique()->all();
        }

        // طلبات استرجاع قديمة نُفّذت يدوياً (is_refunded) بلا صف في refunds.
        if (Schema::hasColumn('refundable_contracts', 'is_refunded')) {
            $legacy = RefundableContract::query()->where('is_refunded', true);
            $this->applyDateRange($legacy, 'created_at', $range);
            if ($refundedContractIds !== [] && Schema::hasColumn('refundable_contracts', 'contract_id')) {
                $legacy->whereNotIn('contract_id', $refundedContractIds);
            }
            $total += (float) $legacy->sum('refund_amount');
        }

        return $this->moneyValue($total);
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    private function refundedCount(?array $range): int
    {
        $query = RefundableContract::query()->where('admin_confirmed', true);
        $this->applyDateRange($query, 'created_at', $range);

        return $query->count();
    }

    /**
     * @return list<array{label: string, value: float}>
     */
    private function salesByQuickPeriod(): array
    {
        $now = now();
        $ranges = [
            'اليوم' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'هذا الشهر' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'هذه السنة' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
        ];

        $result = [];
        foreach ($ranges as $label => $range) {
            $query = Payment::query()->successful();
            $this->applyPaymentDateRange($query, $range);
            $result[] = ['label' => $label, 'value' => $this->moneyValue((float) $query->sum('amount'))];
        }

        return $result;
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return list<array{date: string, value: float}>
     */
    private function salesDaily(?array $range, ?string $contractType, ?int $employeeId): array
    {
        if ($range === null) {
            return [];
        }

        $paymentsQuery = Payment::query()->successful();
        $this->applyPaymentDateRange($paymentsQuery, $range);

        if ($contractType !== null || $employeeId !== null) {
            $paymentsQuery->whereIn('contract_uuid', $this->filteredContractUuids($range, $contractType, $employeeId));
        }

        $byDate = $paymentsQuery
            ->groupBy('payment_date')
            ->selectRaw('payment_date, SUM(amount) as aggregate')
            ->pluck('aggregate', 'payment_date');

        $days = [];
        $cursor = $range[0]->copy()->startOfDay();
        $end = $range[1]->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $days[] = ['date' => $key, 'value' => $this->moneyValue((float) ($byDate[$key] ?? 0))];
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * Revenue grouped by contract_type and by contract period (duration), shaped for
     * sales.revenue_by_contract_type / sales.revenue_by_duration / profits.service_revenue /
     * performance.unit_economics from the same underlying join.
     *
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return array{by_type: list<array<string, mixed>>, by_duration: list<array<string, mixed>>, services: list<array<string, mixed>>, unit_economics: list<array<string, mixed>>}
     */
    private function revenueByTypeAndDuration(?array $range, ?int $employeeId, ?string $contractType = null): array
    {
        $query = $this->paidContractsJoinQuery($range, $contractType, $employeeId);

        $byType = (clone $query)
            ->groupBy('contracts.contract_type')
            ->selectRaw('contracts.contract_type, SUM(payments.amount) as revenue, COUNT(*) as orders_count')
            ->get()
            ->keyBy('contract_type');

        $typeTotal = (float) $byType->sum('revenue');

        $byTypeRows = collect(Contract::CONTRACT_TYPES)
            ->map(function (string $type) use ($byType, $typeTotal) {
                $row = $byType->get($type);
                $revenue = (float) ($row->revenue ?? 0);

                return [
                    'label' => Contract::contractTypeLabel($type),
                    'value' => $this->moneyValue($revenue),
                    'orders_count' => (int) ($row->orders_count ?? 0),
                    'percent' => $typeTotal > 0 ? (int) round($revenue / $typeTotal * 100) : 0,
                ];
            })
            ->values();

        $byDurationRaw = (clone $query)
            ->join('contract_periods', 'contract_periods.id', '=', 'contracts.contract_term_in_years')
            ->groupBy('contract_periods.id', 'contract_periods.note_ar')
            ->selectRaw('contract_periods.id, contract_periods.note_ar as label, SUM(payments.amount) as revenue, COUNT(*) as orders_count')
            ->orderByDesc('revenue')
            ->get();

        $durationTotal = (float) $byDurationRaw->sum('revenue');

        $byDuration = $byDurationRaw->map(fn ($row) => [
            'label' => $row->label,
            'value' => $this->moneyValue((float) $row->revenue),
            'orders_count' => (int) $row->orders_count,
            'percent' => $durationTotal > 0 ? (int) round(((float) $row->revenue) / $durationTotal * 100) : 0,
        ])->values()->all();

        $services = $byTypeRows->map(fn (array $row) => [
            'service' => $row['label'],
            'label' => $row['label'],
            'revenue' => $row['value'],
            'orders_count' => $row['orders_count'],
            'revenue_share_percent' => $row['percent'],
        ])->values()->all();

        $unitEconomics = collect($byDuration)->map(fn (array $row) => [
            'service' => $row['label'],
            'label' => $row['label'],
            'qty' => $row['orders_count'],
            'value' => $row['value'],
            'percent' => $row['percent'],
        ])->values()->all();

        return [
            'by_type' => $byTypeRows->map(fn (array $row) => [
                'label' => $row['label'],
                'value' => $row['value'],
            ])->values()->all(),
            'by_duration' => collect($byDuration)->map(fn (array $row) => [
                'label' => $row['label'],
                'value' => $row['value'],
            ])->values()->all(),
            'services' => $services,
            'unit_economics' => $unitEconomics,
        ];
    }

    // ------------------------------------------------------------------
    // Profits helpers
    // ------------------------------------------------------------------

    /**
     * Profit & loss lines plus the intermediate figures both the profits and
     * performance tabs report, so the two never drift apart.
     *
     * Revenue and variable costs honour the contract-type/employee filters;
     * the monthly fixed budgets (ads, opex, salaries) are company-wide and
     * only prorated over the period length.
     *
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return array{lines: list<array<string, mixed>>, figures: array<string, mixed>}
     */
    private function buildPnl(?array $range, ?string $contractType, ?int $employeeId, bool $includeSalaries): array
    {
        $totals = $this->salesTotals($range, $contractType, $employeeId);
        $settings = Setting::query()->first();
        $costs = $this->periodCosts($range, $contractType, $employeeId, $settings);

        $prorationDays = $this->prorationDays($range);
        $monthlyAds = (float) ($settings?->marketing_budget ?? 0);
        $monthlyOpex = (float) ($settings?->operating_budget ?? 0);
        $monthlySalaries = (float) ($settings?->monthly_salaries ?? 0);
        $adSpend = $this->prorateMonthly($monthlyAds, $prorationDays);
        $operatingExpenses = $this->prorateMonthly($monthlyOpex, $prorationDays);
        $salaries = $this->prorateMonthly($monthlySalaries, $prorationDays);

        $grossProfit = round(
            $totals['net_revenue'] - $costs['ejar'] - $costs['gateway'] - $costs['messaging'],
            2
        );
        $netProfitBeforeSalaries = round($grossProfit - $adSpend - $operatingExpenses, 2);
        $netProfit = $includeSalaries ? round($netProfitBeforeSalaries - $salaries, 2) : $netProfitBeforeSalaries;

        $lines = [
            ['label' => 'دخل العملاء (المحصّل)', 'value' => $this->moneyValue($totals['total_sales'])],
            ['label' => 'مبالغ مسترجعة', 'value' => -$this->moneyValue($totals['refunds_total'])],
            ['label' => 'صافي الإيراد', 'value' => $this->moneyValue($totals['net_revenue']), 'is_subtotal' => true],
            ['label' => 'رسوم منصة إيجار', 'value' => -$this->moneyValue($costs['ejar'])],
            ['label' => 'رسوم بوابة الدفع (موياسر)', 'value' => -$this->moneyValue($costs['gateway'])],
            ['label' => 'تكاليف الرسائل', 'value' => -$this->moneyValue($costs['messaging'])],
            ['label' => 'إجمالي الربح', 'value' => $this->moneyValue($grossProfit), 'is_subtotal' => true],
            ['label' => 'مصاريف الإعلانات', 'value' => -$this->moneyValue($adSpend)],
            ['label' => 'مصاريف تشغيلية', 'value' => -$this->moneyValue($operatingExpenses)],
        ];

        if ($includeSalaries) {
            $lines[] = ['label' => 'الرواتب', 'value' => -$this->moneyValue($salaries)];
        }

        $lines[] = [
            'label' => 'صافي الربح',
            'value' => $this->moneyValue($netProfit),
            'is_total' => true,
            'tone' => $netProfit >= 0 ? 'green' : 'red',
        ];

        return [
            'lines' => $lines,
            'figures' => [
                'sales_totals' => $totals,
                'settings' => $settings,
                'paid_contracts' => $costs['paid_contracts'],
                'paid_count' => $costs['paid_contracts']->count(),
                'meter_units' => $this->meterTransferUnits($costs['paid_contracts']),
                'meter_fee' => $this->meterTransferFee($settings),
                'ejar_fees' => $costs['ejar'],
                'gateway_fee' => $costs['gateway'],
                'messaging_cost' => $costs['messaging'],
                'gross_profit' => $grossProfit,
                'net_profit' => $netProfit,
                'net_profit_before_salaries' => $netProfitBeforeSalaries,
                'ad_spend' => $adSpend,
                'operating_expenses' => $operatingExpenses,
                'salaries' => $salaries,
                'monthly_ads' => $monthlyAds,
                'monthly_opex' => $monthlyOpex,
                'monthly_salaries' => $monthlySalaries,
                'proration_days' => $prorationDays,
            ],
        ];
    }

    /**
     * Variable costs for a period: Ejar platform fees, payment-gateway fees and messaging.
     *
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return array{paid_contracts: \Illuminate\Support\Collection<int, Contract>, ejar: float, gateway: float, messaging: float}
     */
    private function periodCosts(?array $range, ?string $contractType, ?int $employeeId, ?Setting $settings): array
    {
        $paidContracts = $this->ejarFees->paidContracts($range);

        if ($contractType !== null) {
            $paidContracts = $paidContracts
                ->filter(fn (Contract $contract) => $contract->contract_type === $contractType)
                ->values();
        }

        if ($employeeId !== null) {
            $employeeContractIds = DB::table('received_contracts')
                ->where('employee_id', $employeeId)
                ->pluck('contract_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $paidContracts = $paidContracts
                ->filter(fn (Contract $contract) => in_array((int) $contract->id, $employeeContractIds, true))
                ->values();
        }

        $ejar = $paidContracts->isEmpty()
            ? 0.0
            : round((float) $paidContracts->sum(fn (Contract $contract) => EjarPlatformFee::forContract($contract)), 2);

        $paymentsQuery = Payment::query()->successful();
        $this->applyPaymentDateRange($paymentsQuery, $range);

        if ($contractType !== null || $employeeId !== null) {
            $paymentsQuery->whereIn('contract_uuid', $this->filteredContractUuids($range, $contractType, $employeeId));
        }

        $gateway = 0.0;
        foreach ($paymentsQuery->get(['amount', 'payment_method', 'payment_brand']) as $payment) {
            $gateway += $this->moyasarFees->feeFor(
                (float) $payment->amount,
                $payment->payment_method,
                $payment->payment_brand,
                $settings
            );
        }

        return [
            'paid_contracts' => $paidContracts,
            'ejar' => $ejar,
            'gateway' => round($gateway, 2),
            'messaging' => $this->messagingCosts->totalForPeriod($range),
        ];
    }

    /**
     * Unit economics for the four documentation products (list price − Ejar − gateway %).
     *
     * @return list<array<string, mixed>>
     */
    private function serviceProfitability(?Setting $settings): array
    {
        $creditPercent = $this->moyasarFees->creditPercent($settings);

        $rows = [
            [
                'service' => 'توثيق سكني - سنة أولى',
                'doc_fee' => DocFee::HOUSING_FIRST_YEAR,
                'ejar_fee' => EjarPlatformFee::HOUSING_FIRST_YEAR,
            ],
            [
                'service' => 'توثيق تجاري - سنة أولى',
                'doc_fee' => DocFee::COMMERCIAL_FIRST_YEAR,
                'ejar_fee' => EjarPlatformFee::COMMERCIAL_FIRST_YEAR,
            ],
            [
                'service' => 'تجاري - سنة إضافية',
                'doc_fee' => DocFee::COMMERCIAL_EXTRA_YEAR,
                'ejar_fee' => EjarPlatformFee::COMMERCIAL_EXTRA_YEAR,
            ],
            [
                'service' => 'سكني - سنة إضافية',
                'doc_fee' => DocFee::HOUSING_EXTRA_YEAR,
                'ejar_fee' => EjarPlatformFee::HOUSING_EXTRA_YEAR,
            ],
        ];

        return array_map(function (array $row) use ($creditPercent) {
            $gatewayFee = round($row['doc_fee'] * $creditPercent / 100, 2);
            $profit = round($row['doc_fee'] - $row['ejar_fee'] - $gatewayFee, 2);

            return [
                'service' => $row['service'],
                'label' => $row['service'],
                'revenue' => $this->moneyValue($row['doc_fee']),
                'ejar_fee' => $this->moneyValue($row['ejar_fee']),
                'gateway_fee' => $this->moneyValue($gatewayFee),
                'profit' => $this->moneyValue($profit),
                'margin_percent' => $row['doc_fee'] > 0
                    ? (int) round(($profit / $row['doc_fee']) * 100)
                    : 0,
            ];
        }, $rows);
    }

    /**
     * Catalog unit economics (list price − Ejar − Moyasar percent + fixed).
     *
     * @return list<array<string, mixed>>
     */
    private function catalogUnitEconomics(?Setting $settings): array
    {
        $rates = $this->moyasarFees->rates($settings);
        $meterFee = $this->meterTransferFee($settings);

        $rows = [
            [
                'service' => 'توثيق سكني - سنة أولى',
                'customer_pays' => DocFee::HOUSING_FIRST_YEAR,
                'ejar_fee' => EjarPlatformFee::HOUSING_FIRST_YEAR,
                'charge_gateway' => true,
            ],
            [
                'service' => 'توثيق تجاري - سنة أولى',
                'customer_pays' => DocFee::COMMERCIAL_FIRST_YEAR,
                'ejar_fee' => EjarPlatformFee::COMMERCIAL_FIRST_YEAR,
                'charge_gateway' => true,
            ],
            [
                'service' => 'تجاري - سنة إضافية',
                'customer_pays' => DocFee::COMMERCIAL_EXTRA_YEAR,
                'ejar_fee' => EjarPlatformFee::COMMERCIAL_EXTRA_YEAR,
                'charge_gateway' => true,
            ],
            [
                'service' => 'سكني - سنة إضافية',
                'customer_pays' => DocFee::HOUSING_EXTRA_YEAR,
                'ejar_fee' => EjarPlatformFee::HOUSING_EXTRA_YEAR,
                'charge_gateway' => true,
            ],
            [
                'service' => 'نقل العداد',
                'customer_pays' => $meterFee,
                'ejar_fee' => 0.0,
                'charge_gateway' => false,
            ],
        ];

        return array_map(function (array $row) use ($rates) {
            $pays = (float) $row['customer_pays'];
            $ejar = (float) $row['ejar_fee'];
            $moyasar = $row['charge_gateway']
                ? round(($pays * $rates['credit_percent'] / 100) + $rates['fixed_fee'], 2)
                : 0.0;
            $margin = round($pays - $ejar - $moyasar, 2);
            $percent = $pays > 0 ? (int) round(($margin / $pays) * 100) : 0;

            return [
                'service' => $row['service'],
                'label' => $row['service'],
                'customer_pays' => $this->moneyValue($pays),
                'ejar_fee' => $this->moneyValue($ejar),
                'moyasar_fee' => $this->moneyValue($moyasar),
                'margin' => $this->moneyValue($margin),
                'margin_percent' => $percent,
                'low_margin' => $percent < self::LOW_MARGIN_PERCENT,
                'highlight' => $percent < self::LOW_MARGIN_PERCENT,
            ];
        }, $rows);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Contract>  $paidContracts
     * @param  array{total_sales: float, refunds_total: float, net_revenue: float}  $totals
     * @return array<string, mixed>
     */
    private function sourceSummary($paidContracts, array $totals, int $meterUnits, float $meterFee): array
    {
        $housingFirst = 0;
        $commercialFirst = 0;
        $extraHousingYears = 0;
        $extraCommercialYears = 0;

        foreach ($paidContracts as $contract) {
            $years = DocFee::billableYears(EjarPlatformFee::resolveTotalMonths($contract));
            $isCommercial = $contract->contract_type === 'commercial';
            if ($years >= 1) {
                if ($isCommercial) {
                    $commercialFirst++;
                    $extraCommercialYears += $years - 1;
                } else {
                    $housingFirst++;
                    $extraHousingYears += $years - 1;
                }
            }
        }

        $items = [
            [
                'key' => 'housing_first_year',
                'label' => 'توثيق سكني - سنة أولى',
                'orders_count' => $housingFirst,
                'revenue' => $this->moneyValue($housingFirst * DocFee::HOUSING_FIRST_YEAR),
            ],
            [
                'key' => 'commercial_first_year',
                'label' => 'توثيق تجاري - سنة أولى',
                'orders_count' => $commercialFirst,
                'revenue' => $this->moneyValue($commercialFirst * DocFee::COMMERCIAL_FIRST_YEAR),
            ],
            [
                'key' => 'extra_years',
                'label' => 'سنوات إضافية',
                'orders_count' => $extraHousingYears + $extraCommercialYears,
                'revenue' => $this->moneyValue(
                    ($extraHousingYears * DocFee::HOUSING_EXTRA_YEAR)
                    + ($extraCommercialYears * DocFee::COMMERCIAL_EXTRA_YEAR)
                ),
            ],
            [
                'key' => 'meter_transfers',
                'label' => 'نقل العداد',
                'orders_count' => $meterUnits,
                'units_count' => $meterUnits,
                'revenue' => $this->moneyValue($meterUnits * $meterFee),
            ],
        ];

        return [
            'items' => $items,
            'refunds' => $this->moneyValue((float) $totals['refunds_total']),
            'refund_rate_percent' => $totals['total_sales'] > 0
                ? (int) round(($totals['refunds_total'] / $totals['total_sales']) * 100)
                : 0,
            'net_revenue' => $this->moneyValue((float) $totals['net_revenue']),
        ];
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    private function prorationDays(?array $range): int
    {
        if ($range !== null) {
            return max(1, $range[0]->copy()->startOfDay()->diffInDays($range[1]->copy()->startOfDay()) + 1);
        }

        $first = Payment::query()->successful()->min('payment_date');
        if ($first === null) {
            return 1;
        }

        return max(1, Carbon::parse($first)->startOfDay()->diffInDays(now()->startOfDay()) + 1);
    }

    private function prorateMonthly(float $monthly, int $days): float
    {
        return round($monthly * $days / self::PRORATION_MONTH_DAYS, 2);
    }

    private function meterTransferFee(?Setting $settings): float
    {
        if ($settings === null || ! Schema::hasColumn('settings', 'meter_transfer_fee')) {
            return 0.0;
        }

        return $settings->meter_transfer_fee !== null ? (float) $settings->meter_transfer_fee : 0.0;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Contract>  $paidContracts
     */
    private function meterTransferUnits($paidContracts): int
    {
        $hasElectricity = Schema::hasColumn('contracts', 'electricity_meter_ownership');
        $hasWater = Schema::hasColumn('contracts', 'water_meter_ownership');
        if (! $hasElectricity && ! $hasWater) {
            return 0;
        }

        $units = 0;
        foreach ($paidContracts as $contract) {
            if ($hasElectricity && $contract->electricity_meter_ownership === 'tenant') {
                $units++;
            }
            if ($hasWater && $contract->water_meter_ownership === 'tenant') {
                $units++;
            }
        }

        return $units;
    }

    private function paymentContractUuidExpression(): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "CASE WHEN INSTR(payments.contract_uuid, '-') > 0 THEN SUBSTR(payments.contract_uuid, 1, INSTR(payments.contract_uuid, '-') - 1) ELSE payments.contract_uuid END";
        }

        return "SUBSTRING_INDEX(payments.contract_uuid, '-', 1)";
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    private function operatingExpensesTotal(?array $range): float
    {
        $query = OperatingExpense::query();
        $this->applyDateRange($query, 'created_at', $range);

        return $this->moneyValue((float) $query->sum('amount'));
    }

    // ------------------------------------------------------------------
    // Customers helpers
    // ------------------------------------------------------------------

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return list<array<string, mixed>>
     */
    private function topCustomers(?array $range, ?string $contractType = null, ?int $employeeId = null, int $limit = 20): array
    {
        $contractsQuery = $this->ordersBaseQuery($range, $contractType, $employeeId);

        $userIds = (clone $contractsQuery)->pluck('user_id')->filter()->unique();
        if ($userIds->isEmpty()) {
            return [];
        }

        $contractStats = (clone $contractsQuery)
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as contracts_count, SUM(is_completed) as paid_count')
            ->get()
            ->keyBy('user_id');

        $uuidsByUser = (clone $contractsQuery)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'uuid'])
            ->groupBy('user_id');

        $spendingByUser = [];
        foreach ($uuidsByUser as $userId => $contracts) {
            $uuids = $contracts->pluck('uuid')->map(fn ($u) => (string) $u)->all();
            $spendingByUser[$userId] = (float) Payment::query()
                ->successful()
                ->where(function ($q) use ($uuids) {
                    foreach ($uuids as $uuid) {
                        $q->orWhere('contract_uuid', $uuid)->orWhere('contract_uuid', 'like', $uuid.'-%');
                    }
                })
                ->sum('amount');
        }

        arsort($spendingByUser);
        $topUserIds = array_slice(array_keys($spendingByUser), 0, $limit);

        if ($topUserIds === []) {
            return [];
        }

        $users = User::query()->whereIn('id', $topUserIds)->get()->keyBy('id');

        return collect($topUserIds)
            ->map(function ($userId) use ($users, $contractStats, $spendingByUser) {
                $user = $users->get($userId);
                $stats = $contractStats->get($userId);

                return [
                    'customer_id' => (int) $userId,
                    'name' => $user?->name,
                    'mobile' => $user?->mobile,
                    'contracts_count' => (int) ($stats->contracts_count ?? 0),
                    'paid_count' => (int) ($stats->paid_count ?? 0),
                    'total_spending' => $this->moneyValue($spendingByUser[$userId] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    // Performance helpers
    // ------------------------------------------------------------------

    /**
     * Open contracts (not received) waiting more than 24h since creation, within period.
     *
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     */
    private function delayedOpenContractsCount(?array $range): int
    {
        $threshold = now()->subHours(24);

        $query = Contract::query()
            ->notDeleted()
            ->reachedAdminOrderStep()
            ->whereDoesntHave('receivedContract')
            ->where('created_at', '<=', $threshold);

        $this->applyDateRange($query, 'created_at', $range);

        return $query->count();
    }

    /**
     * Contracts that reached the draft stage. Paid and documented orders necessarily
     * passed through it, so the funnel never widens further down.
     *
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @param  list<int>  $doneIds
     */
    private function reachedDraftCount(?array $range, ?string $contractType, ?int $employeeId, array $doneIds): int
    {
        $hasDraftNumber = Schema::hasColumn('contracts', 'ejar_contract_draft_number');

        return $this->ordersBaseQuery($range, $contractType, $employeeId)
            ->where(function ($query) use ($hasDraftNumber, $doneIds) {
                $query->where('is_draft', true)->orWhere('is_completed', 1);

                if ($hasDraftNumber) {
                    $query->orWhereNotNull('ejar_contract_draft_number');
                }

                if ($doneIds !== []) {
                    $query->orWhereIn('contract_status_id', $doneIds);
                }
            })
            ->count();
    }

    /**
     * @param  array<string, int>  $stages  Ordered label => count, top of the funnel first.
     * @return list<array{label: string, value: int, from_previous_pct: int|null}>
     */
    private function conversionFunnel(array $stages): array
    {
        $rows = [];
        $previous = null;

        foreach ($stages as $label => $value) {
            $rows[] = [
                'label' => $label,
                'value' => $value,
                'from_previous_pct' => $previous === null ? null : $this->percentOf($value, $previous),
            ];
            $previous = $value;
        }

        return $rows;
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return list<array{date: string, label: string, value: int}>
     */
    private function dailyOrders(?array $range, ?string $contractType = null, ?int $employeeId = null): array
    {
        // "كل الفترات" has no bounds; show the trailing month so the chart still renders.
        $range ??= [now()->copy()->subDays(29)->startOfDay(), now()->copy()->endOfDay()];

        $byDate = $this->ordersBaseQuery($range, $contractType, $employeeId)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as aggregate')
            ->groupBy('d')
            ->pluck('aggregate', 'd');

        $cursor = $range[0]->copy()->startOfDay();
        $end = $range[1]->copy()->startOfDay();
        $useWeekdayLabels = $cursor->diffInDays($end) <= 7;

        $days = [];
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $days[] = [
                'date' => $key,
                'label' => $useWeekdayLabels ? $this->arabicWeekday($cursor) : $cursor->format('j/n'),
                'value' => (int) ($byDate[$key] ?? 0),
            ];
            $cursor->addDay();
        }

        return $days;
    }

    private function arabicWeekday(Carbon $date): string
    {
        return [
            0 => 'الأحد',
            1 => 'الاثنين',
            2 => 'الثلاثاء',
            3 => 'الأربعاء',
            4 => 'الخميس',
            5 => 'الجمعة',
            6 => 'السبت',
        ][$date->dayOfWeek] ?? $date->format('j/n');
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return list<array{label: string, value: int, revenue: float|int}>
     */
    private function performanceByContractType(?array $range, ?string $contractType, ?int $employeeId): array
    {
        $counts = $this->ordersBaseQuery($range, $contractType, $employeeId)
            ->groupBy('contract_type')
            ->selectRaw('contract_type, COUNT(*) as aggregate')
            ->pluck('aggregate', 'contract_type');

        $revenue = $this->paidContractsJoinQuery($range, $contractType, $employeeId)
            ->groupBy('contracts.contract_type')
            ->selectRaw('contracts.contract_type, SUM(payments.amount) as aggregate')
            ->pluck('aggregate', 'contract_type');

        return collect(Contract::CONTRACT_TYPES)
            ->when($contractType !== null, fn ($types) => $types->filter(fn (string $type) => $type === $contractType))
            ->map(fn (string $type) => [
                'label' => Contract::contractTypeLabel($type),
                'value' => (int) ($counts[$type] ?? 0),
                'revenue' => $this->moneyValue((float) ($revenue[$type] ?? 0)),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return list<array{employee_id: int, label: string, value: int}>
     */
    private function performanceByEmployee(?array $range, ?string $contractType, ?int $employeeId): array
    {
        $rows = $this->ordersByEmployee($range, $contractType);

        if ($employeeId === null) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row) => $row['employee_id'] === $employeeId));
    }

    /**
     * Orders grouped by the deed/instrument kind the customer documented.
     *
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return list<array{label: string, value: int, revenue: float|int}>
     */
    private function performanceByDocumentType(?array $range, ?string $contractType, ?int $employeeId): array
    {
        if (! Schema::hasColumn('contracts', 'instrument_type')) {
            return [];
        }

        $counts = $this->ordersBaseQuery($range, $contractType, $employeeId)
            ->whereNotNull('instrument_type')
            ->groupBy('instrument_type')
            ->selectRaw('instrument_type, COUNT(*) as aggregate')
            ->orderByDesc('aggregate')
            ->pluck('aggregate', 'instrument_type');

        $revenue = $this->paidContractsJoinQuery($range, $contractType, $employeeId)
            ->whereNotNull('contracts.instrument_type')
            ->groupBy('contracts.instrument_type')
            ->selectRaw('contracts.instrument_type, SUM(payments.amount) as aggregate')
            ->pluck('aggregate', 'instrument_type');

        return $counts
            ->map(fn ($count, $type) => [
                'label' => Contract::instrumentTypeLabel((string) $type, 'ar'),
                'value' => (int) $count,
                'revenue' => $this->moneyValue((float) ($revenue[$type] ?? 0)),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return list<array{method: string, label: string, value: float|int}>
     */
    private function revenueByPaymentMethod(?array $range, ?string $contractType = null, ?int $employeeId = null): array
    {
        $query = Payment::query()->successful();
        $this->applyPaymentDateRange($query, $range);

        if ($contractType !== null || $employeeId !== null) {
            $query->whereIn('contract_uuid', $this->filteredContractUuids($range, $contractType, $employeeId));
        }

        return $query
            ->groupBy('payment_method')
            ->selectRaw('payment_method, SUM(amount) as aggregate')
            ->orderByDesc('aggregate')
            ->get()
            ->map(fn ($row) => [
                'method' => (string) $row->payment_method,
                'label' => $this->paymentMethodLabel((string) $row->payment_method),
                'value' => $this->moneyValue((float) $row->aggregate),
            ])
            ->all();
    }

    private function paymentMethodLabel(string $method): string
    {
        return match (strtolower(trim($method))) {
            'mada', 'mada_card' => 'مدى',
            'creditcard', 'credit_card', 'card' => 'بطاقة ائتمان',
            'applepay', 'apple_pay' => 'Apple Pay',
            'stcpay', 'stc_pay' => 'STC Pay',
            'moyasar' => 'موياسر',
            '' => 'غير محدد',
            default => $method,
        };
    }

    /**
     * Revenue per product line, mirroring the profits tab's source summary.
     *
     * @param  array<string, mixed>  $figures
     * @return list<array<string, mixed>>
     */
    private function financialSummary(array $figures): array
    {
        $summary = $this->sourceSummary(
            $figures['paid_contracts'],
            $figures['sales_totals'],
            $figures['meter_units'],
            $figures['meter_fee']
        );

        $rows = array_map(fn (array $item) => [
            'label' => $item['label'],
            'value' => $item['revenue'],
        ], $summary['items']);

        $rows[] = [
            'label' => 'الإجمالي',
            'value' => $summary['net_revenue'],
            'is_total' => true,
        ];

        return $rows;
    }

    /**
     * Refund requests bucketed by the stage their boolean flags represent
     * (there is no rejected state — declined requests are not recorded).
     *
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return list<array{label: string, value: int}>
     */
    private function refundRequestsByStatus(?array $range): array
    {
        $query = RefundableContract::query();
        $this->applyDateRange($query, 'created_at', $range);

        $hasRefundedFlag = Schema::hasColumn('refundable_contracts', 'is_refunded');

        $pending = (clone $query)->where('admin_confirmed', false)->count();
        $refunded = $hasRefundedFlag
            ? (clone $query)->where('is_refunded', true)->count()
            : (clone $query)->where('admin_confirmed', true)->count();
        $approved = (clone $query)->where('admin_confirmed', true)->count() - ($hasRefundedFlag ? $refunded : 0);

        return [
            ['label' => 'قيد المراجعة', 'value' => $pending],
            ['label' => 'موافق عليه', 'value' => max(0, $approved)],
            ['label' => 'منفّذ', 'value' => $refunded],
        ];
    }

    private function percentOf(int|float $value, int|float $total): int
    {
        return $total > 0 ? (int) round(($value / $total) * 100) : 0;
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return array<string, mixed>
     */
    private function operationalMetrics(
        ?array $range,
        ?string $contractType,
        ?int $employeeId,
        int $totalOrders,
        int $delayedCount
    ): array {
        $query = DB::table('received_contracts')
            ->join('contracts', 'contracts.id', '=', 'received_contracts.contract_id')
            ->where('contracts.is_delete', 0)
            ->select([
                'received_contracts.created_at as received_at',
                'received_contracts.date_of_received',
                'contracts.created_at as contract_created_at',
            ]);

        $this->applyDateRange($query, 'received_contracts.created_at', $range);

        if ($contractType !== null) {
            $query->where('contracts.contract_type', $contractType);
        }

        if ($employeeId !== null) {
            $query->where('received_contracts.employee_id', $employeeId);
        }

        $slaSeconds = self::RECEIVE_SLA_MINUTES * 60;
        $lateSeconds = self::RECEIVE_LATE_MINUTES * 60;

        $seconds = [];
        $slaMet = 0;
        $lateOverSla = 0;
        $lateOverLimit = 0;

        foreach ($query->get() as $row) {
            $receivedAt = $row->received_at
                ? Carbon::parse($row->received_at)
                : ($row->date_of_received ? Carbon::parse($row->date_of_received)->startOfDay() : null);
            if ($receivedAt === null || $row->contract_created_at === null) {
                continue;
            }

            $diff = Carbon::parse($row->contract_created_at)->diffInSeconds($receivedAt);
            $seconds[] = $diff;

            if ($diff <= $slaSeconds) {
                $slaMet++;
            } else {
                $lateOverSla++;
            }

            if ($diff > $lateSeconds) {
                $lateOverLimit++;
            }
        }

        $received = count($seconds);
        $avgWait = $received > 0 ? (int) round(array_sum($seconds) / $received) : 0;
        // دفعة (د) — ب6: لا طلبات مستلمة ⇒ null («—») بدل 100%.
        $slaPercent = $received > 0 ? (int) round(($slaMet / $received) * 100) : null;

        $waiting = $this->waitingQueueSeconds($range, $contractType, $employeeId);

        return [
            'total_orders' => $totalOrders,
            'waiting_count' => count($waiting),
            'pending_count' => count($waiting),
            'avg_wait_seconds' => $avgWait,
            'avg_receive_seconds' => $avgWait,
            'longest_wait_seconds' => $waiting === [] ? 0 : (int) max($waiting),
            'max_wait_seconds' => $waiting === [] ? 0 : (int) max($waiting),
            'longest_receive_seconds' => $received > 0 ? (int) max($seconds) : 0,
            'late_over_15m' => $lateOverSla,
            'late_over_15_count' => $lateOverSla,
            'late_over_30m' => $lateOverLimit,
            'late_over_30_count' => $lateOverLimit,
            'sla_percent' => $slaPercent,
            'sla_15m_percent' => $slaPercent,
            'unclaim_count' => 0,
            'unreceive_count' => 0,
            'delayed_over_24h_count' => $delayedCount,
            'sla_minutes' => self::RECEIVE_SLA_MINUTES,
        ];
    }

    /**
     * Seconds each still-unreceived order has been waiting in the queue.
     *
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return list<int>
     */
    private function waitingQueueSeconds(?array $range, ?string $contractType, ?int $employeeId): array
    {
        if ($employeeId !== null) {
            // The queue is unassigned by definition, so an employee filter empties it.
            return [];
        }

        $now = now();

        return $this->ordersBaseQuery($range, $contractType, null)
            ->whereDoesntHave('receivedContract')
            ->pluck('created_at')
            ->filter()
            ->map(fn ($createdAt) => Carbon::parse($createdAt)->diffInSeconds($now))
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    // Formatting
    // ------------------------------------------------------------------

    private function moneyValue(float $amount): int|float
    {
        $rounded = round($amount, 2);

        return abs($rounded - (int) round($rounded)) < 0.005
            ? (int) round($rounded)
            : $rounded;
    }
}
