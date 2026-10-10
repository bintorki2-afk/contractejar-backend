<?php

namespace App\Modules\Analytics\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\V2\Api\AnalyticsPeriodMetricResource;
use App\Http\Resources\Admin\V2\Api\OrderResource;
use App\Http\Traits\Responser;
use App\Services\Admin\Analytics\ContractStatusAnalyticsService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class ContractStatusAnalyticsController extends Controller
{
    use Responser;

    private const METRICS_CONFIG = 'contract_status_analytics_metrics';

    public function __construct(
        protected ContractStatusAnalyticsService $statusAnalytics
    ) {}

    /**
     * Without filter: all period cards.
     * With ?created_at=day|week|month|year|total: single period + OrderResource list.
     *
     * GET /api/admin/analytics/contract-status/2
     * GET /api/admin/analytics/contract-status/2?created_at=month
     */
    public function show(Request $request, int $contractStatusId)
    {
        try {
            $period = $this->statusAnalytics->resolveCreatedAtPeriod($request->query('created_at'));

            if ($period !== null) {
                return $this->periodMetric($request, $contractStatusId, $period);
            }

            return $this->summary($request, $contractStatusId);
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    /**
     * Paginated contracts for a status.
     * GET /api/admin/analytics/contract-status/3/contracts?created_at=month
     */
    public function contracts(Request $request, int $contractStatusId)
    {
        try {
            $status = $this->statusAnalytics->resolveStatus($contractStatusId);
            $rawFilter = $request->query('created_at', $request->query('period'));
            $period = $rawFilter !== null && $rawFilter !== ''
                ? $this->statusAnalytics->resolveCreatedAtPeriod((string) $rawFilter)
                : null;

            $contracts = $this->statusAnalytics->paginateContracts(
                $contractStatusId,
                $period,
                $this->perPageFromRequest($request),
                $request->filled('search') ? $request->string('search')->toString() : null
            );

            $contracts->getCollection()->loadMissing(app(\App\Modules\Contracts\Services\AdminOrderQueryService::class)->orderListRelations());

            return $this->paginatedApiResponse(
                $contracts,
                OrderResource::collection($contracts),
                trans('api.success'),
                [
                    'contract_status_id' => $status->id,
                    'contract_status_name' => $status->name,
                    'created_at' => $period
                        ? $this->statusAnalytics->createdAtLabelForPeriod($period)
                        : null,
                ]
            );
        } catch (ModelNotFoundException) {
            return $this->errorMessage(trans('api.not_found'), 404);
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    /**
     * All period cards (today, week, month, year, total).
     */
    protected function summary(Request $request, int $contractStatusId)
    {
        $status = $this->statusAnalytics->resolveStatus($contractStatusId);
        $metrics = $this->statusAnalytics->getPeriodMetrics($contractStatusId);
        $limit = min(max((int) $request->input('limit', 10), 1), 100);

        $cards = [];
        foreach (config(self::METRICS_CONFIG, []) as $key => $definition) {
            $period = $definition['period'];
            $periodData = $metrics[$period];

            $cards[] = $this->buildCardPayload(
                $key,
                $definition,
                $status,
                $periodData['value'],
                $periodData['percentage_change'],
                $this->statusAnalytics->getContractsForPeriod($contractStatusId, $period, $limit),
                $period
            );
        }

        return $this->apiResponse([
            'contract_status_id' => $status->id,
            'contract_status_name' => $status->name,
            'created_at_filters' => ContractStatusAnalyticsService::CREATED_AT_FILTERS,
            'cards' => collect($cards)->map(
                fn ($card) => (new AnalyticsPeriodMetricResource($card))->resolve()
            )->values(),
        ], trans('api.success'));
    }

    protected function periodMetric(Request $request, int $contractStatusId, string $period)
    {
        $metricKey = $this->statusAnalytics->metricKeyForPeriod($period);
        $definition = config(self::METRICS_CONFIG.'.'.$metricKey);

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown metric key: {$metricKey}");
        }

        $status = $this->statusAnalytics->resolveStatus($contractStatusId);
        $metrics = $this->statusAnalytics->getPeriodMetrics($contractStatusId);
        $periodData = $metrics[$period];
        $limit = min(max((int) $request->input('limit', 10), 1), 100);

        $payload = $this->buildCardPayload(
            $metricKey,
            $definition,
            $status,
            $periodData['value'],
            $periodData['percentage_change'],
            $this->statusAnalytics->getContractsForPeriod($contractStatusId, $period, $limit),
            $period
        );

        $payload['created_at'] = $this->statusAnalytics->createdAtLabelForPeriod($period);

        return $this->apiResponse(
            (new AnalyticsPeriodMetricResource($payload))->resolve(),
            trans('api.success')
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\Contract>|\Illuminate\Contracts\Pagination\LengthAwarePaginator  $contracts
     * @return array<string, mixed>
     */
    private function buildCardPayload(
        string $key,
        array $definition,
        $status,
        int $value,
        ?float $percentageChange,
        $contracts,
        string $period
    ): array {
        $statusName = $status->name;
        if ($contracts instanceof \Illuminate\Pagination\AbstractPaginator) {
            $contracts->getCollection()->loadMissing(app(\App\Modules\Contracts\Services\AdminOrderQueryService::class)->orderListRelations());
        } elseif ($contracts instanceof \Illuminate\Database\Eloquent\Collection) {
            $contracts->loadMissing(app(\App\Modules\Contracts\Services\AdminOrderQueryService::class)->orderListRelations());
        }
        $orders = OrderResource::collection($contracts)->resolve();

        return [
            'key' => $key,
            'label_ar' => "عقود {$statusName} — {$definition['label_ar']}",
            'label_en' => "Contracts ({$statusName}) — ".($definition['label_en'] ?? $definition['label_ar']),
            'value' => $value,
            'type' => $definition['type'],
            'percentage_change' => $percentageChange,
            'created_at' => $this->statusAnalytics->createdAtLabelForPeriod($period),
            'contracts' => $orders,
            'items' => $orders,
            'meta' => [
                'contract_status_id' => $status->id,
                'contract_status_name' => $statusName,
                'period' => $period,
                'created_at' => $this->statusAnalytics->createdAtLabelForPeriod($period),
            ],
        ];
    }
}
