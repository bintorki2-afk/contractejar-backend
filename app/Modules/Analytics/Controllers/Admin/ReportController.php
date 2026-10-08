<?php

namespace App\Modules\Analytics\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportAdSpendRequest;
use App\Http\Resources\Admin\V2\Api\Reports\ReportJsonResource;
use App\Http\Traits\Responser;
use App\Models\Employee;
use App\Services\Admin\MarketingReportsService;
use App\Services\Admin\ReportsService;
use App\Services\Marketing\AdSpendSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class ReportController extends Controller
{
    use Responser;

    public function __construct(
        protected ReportsService $reports,
        protected MarketingReportsService $marketingReports,
        protected AdSpendSyncService $adSpend
    ) {}

    public function orders(Request $request)
    {
        try {
            $filter = $this->reports->resolveReportPeriodFilter($request);

            return $this->reportResponse(
                $this->reports->orders($filter, $this->contractType($request), $this->employeeId($request)),
                $filter
            );
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function sales(Request $request)
    {
        try {
            $filter = $this->reports->resolveReportPeriodFilter($request);

            return $this->reportResponse(
                $this->reports->sales($filter, $this->contractType($request), $this->employeeId($request)),
                $filter
            );
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function profits(Request $request)
    {
        try {
            $filter = $this->reports->resolveReportPeriodFilter($request);

            return $this->reportResponse(
                $this->reports->profits($filter, $this->canSeeSalaries($request)),
                $filter
            );
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function profitSettingsShow(Request $request)
    {
        try {
            return $this->apiResponse(
                $this->reports->profitSettings($this->canSeeSalaries($request))->resolve(),
                trans('api.success')
            );
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function profitSettingsUpdate(Request $request)
    {
        try {
            $data = $request->validate([
                'moyasar_fee_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
                'moyasar_mada_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
                'moyasar_credit_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
                'moyasar_fixed_fee' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'monthly_salaries' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'operating_budget' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'marketing_budget' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'meter_transfer_fee' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            ]);

            return $this->apiResponse(
                $this->reports->updateProfitSettings($data, $this->canEditSalaries($request))->resolve(),
                trans('api.updated_successfully')
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function customers(Request $request)
    {
        try {
            $filter = $this->reports->resolveReportPeriodFilter($request);

            return $this->reportResponse(
                $this->reports->customers(
                    $filter,
                    $this->contractType($request),
                    $this->employeeId($request)
                ),
                $filter
            );
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function performance(Request $request)
    {
        try {
            $filter = $this->reports->resolveReportPeriodFilter($request);

            return $this->reportResponse(
                $this->reports->performance(
                    $filter,
                    $this->contractType($request),
                    $this->employeeId($request),
                    $this->canSeeSalaries($request)
                ),
                $filter
            );
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    /** GET /api/admin/reports/overview?range=today|week|month|year|all (افتراضي week). */
    public function overview(Request $request)
    {
        return $this->apiResponse(
            app(\App\Services\Admin\ReportsOverviewService::class)->overview((string) $request->query('range', 'week')),
            trans('api.success')
        );
    }

    public function marketing(Request $request)
    {
        try {
            $filter = $this->reports->resolveReportPeriodFilter($request);

            return $this->apiResponse(array_merge(
                $this->periodMeta($filter),
                $this->marketingReports->dashboard($filter)
            ), trans('api.success'));
        } catch (InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function marketingUtmTemplate()
    {
        try {
            return $this->apiResponse(
                $this->marketingReports->utmTemplate(),
                trans('api.success')
            );
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function importAdSpend(ImportAdSpendRequest $request)
    {
        try {
            $rows = array_map(static function (array $row): array {
                return [
                    'spent_on' => $row['spent_on'],
                    'platform' => $row['platform'],
                    'campaign_id' => (string) ($row['campaign_id'] ?? ''),
                    'campaign_name' => $row['campaign_name'] ?? null,
                    'keyword' => (string) ($row['keyword'] ?? ''),
                    'spend' => (float) $row['spend'],
                    'currency' => strtoupper((string) ($row['currency'] ?? 'SAR')),
                    'impressions' => $row['impressions'] ?? null,
                    'clicks' => $row['clicks'] ?? null,
                ];
            }, $request->validated('rows'));

            $synced = $this->adSpend->upsert($rows, 'manual');

            return $this->apiResponse(
                ['synced' => $synced],
                trans('api.ad_spend_imported')
            );
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    public function syncAdSpend(Request $request)
    {
        try {
            $data = $request->validate([
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date', 'after_or_equal:from'],
                'platform' => ['nullable', 'string', Rule::in(array_keys(config('ads.platforms', [])))],
                'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            ]);

            $to = isset($data['to'])
                ? Carbon::parse($data['to'])->endOfDay()
                : now()->endOfDay();
            $from = isset($data['from'])
                ? Carbon::parse($data['from'])->startOfDay()
                : $to->copy()->subDays((int) ($data['days'] ?? 3) - 1)->startOfDay();

            return $this->apiResponse(
                $this->adSpend->sync($from, $to, $data['platform'] ?? null),
                trans('api.ad_spend_synced')
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    /**
     * @param  array{key: string, date_from: string|null, date_to: string|null}  $filter
     */
    private function reportResponse(ReportJsonResource $resource, array $filter)
    {
        return $this->apiResponse(
            $resource->withPeriod($this->periodMeta($filter))->resolve(),
            trans('api.success')
        );
    }

    /**
     * @param  array{key: string, date_from: string|null, date_to: string|null}  $filter
     * @return array<string, mixed>
     */
    private function periodMeta(array $filter): array
    {
        return [
            'periods' => $this->reports->reportPeriodTabs($filter['key']),
            'period' => $filter['key'],
            'date_from' => $filter['date_from'],
            'date_to' => $filter['date_to'],
        ];
    }

    private function contractType(Request $request): ?string
    {
        $type = $request->query('contract_type');

        return in_array($type, ['residential', 'housing', 'commercial'], true)
            ? ($type === 'residential' ? 'housing' : $type)
            : null;
    }

    private function employeeId(Request $request): ?int
    {
        return $request->filled('employee_id') && is_numeric($request->query('employee_id'))
            ? (int) $request->query('employee_id')
            : null;
    }

    private function canSeeSalaries(Request $request): bool
    {
        $employee = $request->user();

        return $employee instanceof Employee && $employee->hasPermission('employee_salaries.view');
    }

    private function canEditSalaries(Request $request): bool
    {
        $employee = $request->user();

        return $employee instanceof Employee && $employee->hasPermission('employee_salaries.edit');
    }
}
