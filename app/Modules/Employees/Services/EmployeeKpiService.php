<?php

namespace App\Modules\Employees\Services;

use App\Models\ContractComment;
use App\Models\ContractStatus;
use App\Models\ContractStatusHistory;
use App\Models\Employee;
use App\Models\ReceivedContract;
use App\Support\ContractReceivedTiming;
use App\Support\ContractStatusCase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EmployeeKpiService
{
    public const PERIODS = [
        'today' => 'اليوم',
        'yesterday' => 'أمس',
        'last_7_days' => 'آخر 7 أيام',
        'last_30_days' => 'آخر 30 يومًا',
        'all' => 'كل الفترات',
        'custom' => 'مدة محددة',
    ];

    /**
     * @return list<array{key: string, label_ar: string, selected: bool}>
     */
    public function periodTabs(string $selected): array
    {
        $selected = $this->normalizePeriod($selected);

        return collect(self::PERIODS)
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label_ar' => $label,
                'selected' => $key === $selected,
            ])
            ->values()
            ->all();
    }

    public function normalizePeriod(?string $raw): string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return 'today';
        }

        $aliases = [
            'today' => 'today',
            'اليوم' => 'today',
            'yesterday' => 'yesterday',
            'امس' => 'yesterday',
            'أمس' => 'yesterday',
            'last_7_days' => 'last_7_days',
            '7d' => 'last_7_days',
            'week' => 'last_7_days',
            'اخر 7 ايام' => 'last_7_days',
            'آخر 7 أيام' => 'last_7_days',
            'last_30_days' => 'last_30_days',
            '30d' => 'last_30_days',
            'month' => 'last_30_days',
            'اخر 30 يوما' => 'last_30_days',
            'آخر 30 يومًا' => 'last_30_days',
            'all' => 'all',
            'كل الفترات' => 'all',
            'custom' => 'custom',
            'مدة محددة' => 'custom',
        ];

        $normalized = mb_strtolower(str_replace('ً', '', $value));

        return $aliases[$value] ?? $aliases[$normalized] ?? 'today';
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function periodRange(string $period): ?array
    {
        $period = $this->normalizePeriod($period);
        $now = now();

        return match ($period) {
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'last_7_days' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'last_30_days' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'all', 'custom' => null,
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    /**
     * @return array{
     *     key: string,
     *     label_ar: string,
     *     range: array{0: Carbon, 1: Carbon}|null,
     *     date_from: string|null,
     *     date_to: string|null
     * }
     */
    public function resolvePeriodFilter(Request $request): array
    {
        $dateFromRaw = $this->queryString($request, 'date_from');
        $dateToRaw = $this->queryString($request, 'date_to');
        $hasFrom = $dateFromRaw !== null;
        $hasTo = $dateToRaw !== null;

        if ($hasFrom xor $hasTo) {
            throw new InvalidArgumentException('يجب تحديد date_from و date_to معاً.');
        }

        $period = $this->normalizePeriod($request->query('period'));

        if ($hasFrom && $hasTo) {
            $from = $this->parseDateBoundary($dateFromRaw, false);
            $to = $this->parseDateBoundary($dateToRaw, true);
            if ($from->gt($to)) {
                throw new InvalidArgumentException('date_from يجب أن يكون قبل أو يساوي date_to.');
            }

            return [
                'key' => 'custom',
                'label_ar' => self::PERIODS['custom'],
                'range' => [$from, $to],
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ];
        }

        if ($period === 'custom') {
            throw new InvalidArgumentException('يجب تحديد date_from و date_to عند اختيار مدة محددة.');
        }

        return $this->filterFromPeriodKey($period);
    }

    /**
     * @return array{
     *     key: string,
     *     label_ar: string,
     *     range: array{0: Carbon, 1: Carbon}|null,
     *     date_from: string|null,
     *     date_to: string|null
     * }
     */
    public function filterFromPeriodKey(string $period): array
    {
        $period = $this->normalizePeriod($period);
        if ($period === 'custom') {
            $period = 'today';
        }

        $range = $this->periodRange($period);

        return [
            'key' => $period,
            'label_ar' => self::PERIODS[$period],
            'range' => $range,
            'date_from' => $range === null ? null : $range[0]->toDateString(),
            'date_to' => $range === null ? null : $range[1]->toDateString(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function summarize(array $items): array
    {
        $received = 0;
        $completed = 0;
        $late = 0;
        $assigned = 0;
        $returned = 0;
        $receiveAvgs = [];
        $processAvgs = [];
        $revenue = 0.0;

        foreach ($items as $item) {
            $cards = collect($item['cards'] ?? [])->keyBy('key');
            $received += (int) ($cards->get('received')['value'] ?? 0);
            $completed += (int) ($cards->get('completed')['value'] ?? 0);
            $late += (int) ($cards->get('late_over_24h')['value'] ?? 0);
            $assigned += (int) ($cards->get('assigned')['value'] ?? 0);
            $returned += (int) ($cards->get('returned')['value'] ?? 0);

            $receiveAvg = $item['avg_receive']['value'] ?? null;
            if (is_numeric($receiveAvg)) {
                $receiveAvgs[] = (float) $receiveAvg;
            }

            $processAvg = $item['avg_process']['value'] ?? null;
            if (is_numeric($processAvg)) {
                $processAvgs[] = (float) $processAvg;
            }

            $revenue += (float) ($item['revenue']['value'] ?? 0);
        }

        return [
            'employees_count' => count($items),
            'received_total' => $received,
            'completed_total' => $completed,
            'late_over_24h_total' => $late,
            'assigned_total' => $assigned,
            'returned_total' => $returned,
            'avg_receive_work_minutes' => $this->averageOrNull($receiveAvgs),
            'avg_process_minutes' => $this->averageOrNull($processAvgs),
            'revenue_sar_total' => $this->moneyValue($revenue),
        ];
    }

    /**
     * @param  array{
     *     key: string,
     *     label_ar: string,
     *     range: array{0: Carbon, 1: Carbon}|null,
     *     date_from: string|null,
     *     date_to: string|null
     * }|string  $period
     * @return array<string, mixed>
     */
    public function forEmployee(Employee $employee, array|string $period, ?int $viewerEmployeeId = null, bool $withDetails = true): array
    {
        $filter = is_array($period) ? $period : $this->filterFromPeriodKey($period);
        $payloads = $this->buildForEmployees(collect([$employee]), $filter, $viewerEmployeeId, $withDetails);

        return $payloads[0];
    }

    /**
     * @param  array{
     *     key: string,
     *     label_ar: string,
     *     range: array{0: Carbon, 1: Carbon}|null,
     *     date_from: string|null,
     *     date_to: string|null
     * }|string  $period
     * @return list<array<string, mixed>>
     */
    public function forAllEmployees(array|string $period, ?int $viewerEmployeeId = null): array
    {
        $filter = is_array($period) ? $period : $this->filterFromPeriodKey($period);
        $employees = Employee::query()
            ->with('roleRelation')
            ->orderBy('name')
            ->get();

        return $this->buildForEmployees($employees, $filter, $viewerEmployeeId, false);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  array{
     *     key: string,
     *     label_ar: string,
     *     range: array{0: Carbon, 1: Carbon}|null,
     *     date_from: string|null,
     *     date_to: string|null
     * }  $filter
     * @return list<array<string, mixed>>
     */
    private function buildForEmployees(
        Collection $employees,
        array $filter,
        ?int $viewerEmployeeId,
        bool $withDetails
    ): array {
        $period = $filter['key'];
        $periodLabel = $filter['label_ar'] ?? (self::PERIODS[$period] ?? $period);
        $range = $filter['range'] ?? $this->periodRange($period);
        $doneIds = $this->doneStatusIds();
        $returnIds = $this->returnStatusIds();
        $notOpenIds = $this->notOpenStatusIds();
        $lateBefore = now()->subHours((int) config('employee_kpis.late_after_hours', 24));
        $ids = $employees->pluck('id')->all();

        $openCounts = $this->countOpenByEmployee($ids, $notOpenIds);
        $lateCounts = $this->countLateByEmployee($ids, $notOpenIds, $lateBefore);
        $receivedCounts = $this->countReceivedInPeriodByEmployee($ids, $range);
        $completedCounts = $this->countCompletedInPeriodByEmployee($ids, $doneIds, $range);
        $returnedCounts = $this->countReturnedInPeriodByEmployee($ids, $returnIds, $range);
        $revenueByEmployee = $this->revenueByEmployee($ids, $doneIds, $range);
        $receiveRows = $this->receivedRowsInPeriod($ids, $range);
        $processRows = $this->processRowsInPeriod($ids, $doneIds, $range);

        $payloads = [];
        foreach ($employees as $employee) {
            $employeeId = (int) $employee->id;
            $open = (int) ($openCounts[$employeeId] ?? 0);
            $late = (int) ($lateCounts[$employeeId] ?? 0);
            $received = (int) ($receivedCounts[$employeeId] ?? 0);
            $completed = (int) ($completedCounts[$employeeId] ?? 0);
            $returned = (int) ($returnedCounts[$employeeId] ?? 0);
            $assigned = $received;
            $revenue = (float) ($revenueByEmployee[$employeeId] ?? 0);
            $rows = $receiveRows->get($employeeId, collect());
            $receiveStats = $this->receiveWorkStats($employee, $rows);
            $processStats = $this->processTimeStats($processRows->get($employeeId, collect()));
            $slaMinutes = (int) config('employee_kpis.receive_sla_minutes', 5);

            $isYou = $viewerEmployeeId !== null && $viewerEmployeeId === $employeeId;
            $shift = $this->shiftFor($employee);
            $onDuty = $this->isOnDutyNow($shift);
            $receiveLabel = $period === 'custom' && $filter['date_from'] && $filter['date_to']
                ? $filter['date_from'].' → '.$filter['date_to']
                : $periodLabel;

            $payload = [
                'employee' => [
                    'id' => $employeeId,
                    'name' => $employee->name,
                    'name_label' => $isYou ? $employee->name.' (أنت)' : $employee->name,
                    'is_you' => $isYou,
                    'role' => $employee->resolvedRoleName(),
                    'role_title' => $employee->resolvedRoleTitle(),
                    'profile_image' => $employee->profile_image ? url($employee->profile_image) : null,
                    'is_active' => (bool) $employee->is_active,
                    'is_online' => (bool) $employee->is_online,
                ],
                'shift' => [
                    'name' => $shift['name'],
                    'start' => $shift['start'],
                    'end' => $shift['end'],
                    'label_ar' => $shift['name'].' '.$this->formatClockAr($shift['start']).' - '.$this->formatClockAr($shift['end']),
                    'is_on_duty' => $onDuty,
                    'duty_status' => $onDuty ? 'inside' : 'outside',
                    'duty_status_label_ar' => $onDuty ? 'داخل الدوام الآن' : 'خارج الدوام',
                ],
                'period' => [
                    'key' => $period,
                    'label_ar' => $periodLabel,
                    'from' => $range === null ? null : $range[0]->format('Y-m-d H:i:s'),
                    'to' => $range === null ? null : $range[1]->format('Y-m-d H:i:s'),
                    'date_from' => $filter['date_from'] ?? ($range === null ? null : $range[0]->toDateString()),
                    'date_to' => $filter['date_to'] ?? ($range === null ? null : $range[1]->toDateString()),
                ],
                'cards' => [
                    [
                        'key' => 'received',
                        'label_ar' => 'استلم ('.$receiveLabel.')',
                        'value' => $received,
                        'tone' => 'default',
                    ],
                    [
                        'key' => 'completed',
                        'label_ar' => 'منجز بالفترة',
                        'value' => $completed,
                        'tone' => 'default',
                    ],
                    [
                        'key' => 'open_now',
                        'label_ar' => 'مفتوح الآن',
                        'value' => $open,
                        'tone' => 'default',
                    ],
                    [
                        'key' => 'late_over_24h',
                        'label_ar' => 'متأخر > 24 س',
                        'value' => $late,
                        'tone' => 'danger',
                    ],
                    [
                        'key' => 'assigned',
                        'label_ar' => 'طلبات مسندة',
                        'value' => $assigned,
                        'tone' => 'default',
                    ],
                    [
                        'key' => 'returned',
                        'label_ar' => 'مسترجع',
                        'value' => $returned,
                        'tone' => $returned > 0 ? 'warning' : 'default',
                    ],
                ],
                'avg_receive' => [
                    'key' => 'avg_receive_work_minutes',
                    'label_ar' => 'متوسط الاستلام (د عمل)',
                    'value' => $receiveStats['avg'],
                    'value_label' => $receiveStats['avg'] === null ? '—' : (string) $receiveStats['avg'],
                    'unit' => 'د عمل',
                ],
                'avg_process' => [
                    'key' => 'avg_process_minutes',
                    'label_ar' => 'متوسط المعالجة',
                    'value' => $processStats['avg'],
                    'value_label' => $processStats['avg'] === null
                        ? '—'
                        : ContractReceivedTiming::compactDurationPhrase((int) round($processStats['avg'])),
                    'unit' => 'دقيقة',
                ],
                'revenue' => [
                    'key' => 'revenue_sar',
                    'label_ar' => 'إيراد محقق',
                    'value' => $this->moneyValue($revenue),
                    'currency' => 'SAR',
                ],
                'receive_sla' => [
                    'key' => 'receive_sla_within_5m',
                    'label_ar' => 'التزام الاستلام ≤'.$slaMinutes.'د',
                    'percent' => $receiveStats['sla_percent'],
                    'threshold_minutes' => $slaMinutes,
                    'met_count' => $receiveStats['sla_met'],
                    'total' => $receiveStats['sla_total'],
                ],
                'metrics' => [
                    [
                        'key' => 'avg_receive_work_minutes',
                        'label_ar' => 'متوسط الاستلام (د عمل)',
                        'value' => $receiveStats['avg'],
                        'value_label' => $receiveStats['avg'] === null ? '—' : (string) $receiveStats['avg'],
                        'unit' => 'د عمل',
                    ],
                    [
                        'key' => 'receive_sla_within_5m',
                        'label_ar' => 'التزام الاستلام ≤'.$slaMinutes.'د',
                        'percent' => $receiveStats['sla_percent'],
                        'tone' => $receiveStats['sla_percent'] >= 90 ? 'success' : ($receiveStats['sla_percent'] >= 50 ? 'warning' : 'danger'),
                    ],
                    [
                        'key' => 'avg_process_minutes',
                        'label_ar' => 'متوسط المعالجة',
                        'value' => $processStats['avg'],
                        'value_label' => $processStats['avg'] === null
                            ? '—'
                            : ContractReceivedTiming::compactDurationPhrase((int) round($processStats['avg'])),
                        'unit' => 'دقيقة',
                    ],
                    [
                        'key' => 'revenue_sar',
                        'label_ar' => 'إيراد محقق',
                        'value' => $this->moneyValue($revenue),
                        'currency' => 'SAR',
                    ],
                ],
                'details_path' => '/api/admin/employees/'.$employeeId.'/kpis/details',
            ];

            if ($withDetails) {
                $payload['received_orders'] = $this->receivedOrdersTable($employee, $periodLabel, $range);
                $payload['activity'] = [
                    'label_ar' => 'سجل التحركات الكامل',
                    'items' => $this->activityForEmployee(
                        $employee,
                        (int) config('employee_kpis.activity_full_limit', 2000)
                    ),
                ];
            }

            $payloads[] = $payload;
        }

        return $payloads;
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

    /**
     * @param  list<int>  $employeeIds
     * @param  list<int>  $notOpenIds
     * @return array<int, int>
     */
    private function countOpenByEmployee(array $employeeIds, array $notOpenIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $query = ReceivedContract::query()
            ->join('contracts', 'contracts.id', '=', 'received_contracts.contract_id')
            ->whereIn('received_contracts.employee_id', $employeeIds)
            ->where('contracts.is_delete', 0);

        if ($notOpenIds !== []) {
            $query->where(function ($q) use ($notOpenIds) {
                $q->whereNull('contracts.contract_status_id')
                    ->orWhereNotIn('contracts.contract_status_id', $notOpenIds);
            });
        }

        return $query
            ->groupBy('received_contracts.employee_id')
            ->selectRaw('received_contracts.employee_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'employee_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  list<int>  $employeeIds
     * @param  list<int>  $notOpenIds
     * @return array<int, int>
     */
    private function countLateByEmployee(array $employeeIds, array $notOpenIds, Carbon $lateBefore): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $query = ReceivedContract::query()
            ->join('contracts', 'contracts.id', '=', 'received_contracts.contract_id')
            ->whereIn('received_contracts.employee_id', $employeeIds)
            ->where('contracts.is_delete', 0)
            ->whereRaw('COALESCE(received_contracts.created_at, CAST(received_contracts.date_of_received AS DATETIME)) <= ?', [
                $lateBefore->toDateTimeString(),
            ]);

        if ($notOpenIds !== []) {
            $query->where(function ($q) use ($notOpenIds) {
                $q->whereNull('contracts.contract_status_id')
                    ->orWhereNotIn('contracts.contract_status_id', $notOpenIds);
            });
        }

        return $query
            ->groupBy('received_contracts.employee_id')
            ->selectRaw('received_contracts.employee_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'employee_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  list<int>  $employeeIds
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return array<int, int>
     */
    private function countReceivedInPeriodByEmployee(array $employeeIds, ?array $range): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $query = ReceivedContract::query()
            ->join('contracts', 'contracts.id', '=', 'received_contracts.contract_id')
            ->whereIn('received_contracts.employee_id', $employeeIds)
            ->where('contracts.is_delete', 0);

        $this->applyReceivedAtRange($query, $range);

        return $query
            ->groupBy('received_contracts.employee_id')
            ->selectRaw('received_contracts.employee_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'employee_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  list<int>  $employeeIds
     * @param  list<int>  $doneIds
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return array<int, int>
     */
    private function countCompletedInPeriodByEmployee(array $employeeIds, array $doneIds, ?array $range): array
    {
        if ($employeeIds === [] || $doneIds === []) {
            return [];
        }

        $completed = $this->firstStatusEventSubquery($doneIds, 'completed_at');

        $query = DB::table('received_contracts')
            ->joinSub($completed, 'done', 'done.contract_id', '=', 'received_contracts.contract_id')
            ->join('contracts', 'contracts.id', '=', 'received_contracts.contract_id')
            ->whereIn('received_contracts.employee_id', $employeeIds)
            ->where('contracts.is_delete', 0);

        if ($range !== null) {
            $query->whereBetween('done.completed_at', [
                $range[0]->toDateTimeString(),
                $range[1]->toDateTimeString(),
            ]);
        }

        return $query
            ->groupBy('received_contracts.employee_id')
            ->selectRaw('received_contracts.employee_id, COUNT(DISTINCT received_contracts.contract_id) as aggregate')
            ->pluck('aggregate', 'employee_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  list<int>  $employeeIds
     * @param  list<int>  $returnIds
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return array<int, int>
     */
    private function countReturnedInPeriodByEmployee(array $employeeIds, array $returnIds, ?array $range): array
    {
        if ($employeeIds === [] || $returnIds === []) {
            return [];
        }

        $returned = $this->firstStatusEventSubquery($returnIds, 'returned_at');

        $query = DB::table('received_contracts')
            ->joinSub($returned, 'returned', 'returned.contract_id', '=', 'received_contracts.contract_id')
            ->join('contracts', 'contracts.id', '=', 'received_contracts.contract_id')
            ->whereIn('received_contracts.employee_id', $employeeIds)
            ->where('contracts.is_delete', 0);

        if ($range !== null) {
            $query->whereBetween('returned.returned_at', [
                $range[0]->toDateTimeString(),
                $range[1]->toDateTimeString(),
            ]);
        }

        return $query
            ->groupBy('received_contracts.employee_id')
            ->selectRaw('received_contracts.employee_id, COUNT(DISTINCT received_contracts.contract_id) as aggregate')
            ->pluck('aggregate', 'employee_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  list<int>  $employeeIds
     * @param  list<int>  $doneIds
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return array<int, float>
     */
    private function revenueByEmployee(array $employeeIds, array $doneIds, ?array $range): array
    {
        if ($employeeIds === [] || $doneIds === []) {
            return [];
        }

        $completed = $this->firstStatusEventSubquery($doneIds, 'completed_at');

        $query = DB::table('received_contracts')
            ->joinSub($completed, 'done', 'done.contract_id', '=', 'received_contracts.contract_id')
            ->join('contracts', 'contracts.id', '=', 'received_contracts.contract_id')
            ->join('payments', function ($join) {
                $join->where('payments.status', '=', 'success')
                    ->where(function ($q) {
                        $q->whereColumn('payments.contract_uuid', 'contracts.uuid')
                            ->orWhereRaw("payments.contract_uuid LIKE CONCAT(CAST(contracts.uuid AS CHAR), '-%')");
                    });
            })
            ->whereIn('received_contracts.employee_id', $employeeIds)
            ->where('contracts.is_delete', 0);

        if ($range !== null) {
            $query->whereBetween('done.completed_at', [
                $range[0]->toDateTimeString(),
                $range[1]->toDateTimeString(),
            ]);
        }

        return $query
            ->groupBy('received_contracts.employee_id')
            ->selectRaw('received_contracts.employee_id, COALESCE(SUM(payments.amount), 0) as aggregate')
            ->pluck('aggregate', 'employee_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * @param  list<int>  $employeeIds
     * @param  list<int>  $doneIds
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return Collection<int, Collection<int, object>>
     */
    private function processRowsInPeriod(array $employeeIds, array $doneIds, ?array $range): Collection
    {
        if ($employeeIds === [] || $doneIds === []) {
            return collect();
        }

        $completed = $this->firstStatusEventSubquery($doneIds, 'completed_at');

        $query = DB::table('received_contracts')
            ->joinSub($completed, 'done', 'done.contract_id', '=', 'received_contracts.contract_id')
            ->join('contracts', 'contracts.id', '=', 'received_contracts.contract_id')
            ->whereIn('received_contracts.employee_id', $employeeIds)
            ->where('contracts.is_delete', 0)
            ->select([
                'received_contracts.employee_id',
                'received_contracts.contract_id',
                'received_contracts.created_at as received_at',
                'received_contracts.date_of_received',
                'done.completed_at',
            ]);

        if ($range !== null) {
            $query->whereBetween('done.completed_at', [
                $range[0]->toDateTimeString(),
                $range[1]->toDateTimeString(),
            ]);
        }

        return $query->get()->groupBy('employee_id');
    }

    /**
     * @param  list<int>  $statusIds
     */
    private function firstStatusEventSubquery(array $statusIds, string $alias): \Illuminate\Database\Query\Builder
    {
        return DB::table('contract_status_histories')
            ->select('contract_id')
            ->selectRaw('MIN(created_at) as '.$alias)
            ->whereIn('status_id', $statusIds)
            ->groupBy('contract_id');
    }

    /**
     * @return list<int>
     */
    private function returnStatusIds(): array
    {
        $ids = array_values(array_unique([
            ...ContractStatus::idsFor([ContractStatus::KEY_REFUNDED]),
            ...ContractStatus::query()
                ->whereIn('name', ['مسترجع', 'استرجاع'])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        ]));

        // لا حالة استرجاع ⇒ لا شيء يطابق (كان يرجع الرقم 2 = «قيد المراجعة» خطأً).
        return $ids === [] ? [-1] : $ids;
    }

    /**
     * @param  list<int>  $employeeIds
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return Collection<int, Collection<int, object>>
     */
    private function receivedRowsInPeriod(array $employeeIds, ?array $range): Collection
    {
        if ($employeeIds === []) {
            return collect();
        }

        $query = ReceivedContract::query()
            ->join('contracts', 'contracts.id', '=', 'received_contracts.contract_id')
            ->whereIn('received_contracts.employee_id', $employeeIds)
            ->where('contracts.is_delete', 0)
            ->select([
                'received_contracts.employee_id',
                'received_contracts.contract_id',
                'received_contracts.created_at as received_at',
                'received_contracts.date_of_received',
                'contracts.created_at as contract_created_at',
            ]);

        $this->applyReceivedAtRange($query, $range);

        return $query->get()->groupBy('employee_id');
    }

    private function applyReceivedAtRange($query, ?array $range): void
    {
        if ($range === null) {
            return;
        }

        $query->whereRaw(
            'COALESCE(received_contracts.created_at, CAST(received_contracts.date_of_received AS DATETIME)) BETWEEN ? AND ?',
            [$range[0]->toDateTimeString(), $range[1]->toDateTimeString()]
        );
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{avg: float|null, sla_percent: int, sla_met: int, sla_total: int}
     */
    private function receiveWorkStats(Employee $employee, Collection $rows): array
    {
        $shift = $this->shiftFor($employee);
        $sla = (int) config('employee_kpis.receive_sla_minutes', 5);
        $workMinutes = [];
        $slaMet = 0;

        foreach ($rows as $row) {
            $minutes = $this->rowWorkMinutes($row, $shift);
            if ($minutes === null) {
                continue;
            }
            $workMinutes[] = $minutes;
            if ($minutes <= $sla) {
                $slaMet++;
            }
        }

        $total = count($workMinutes);
        $avg = null;
        if ($total > 0) {
            $rawAvg = array_sum($workMinutes) / $total;
            $avg = abs($rawAvg - round($rawAvg)) < 0.05
                ? (float) (int) round($rawAvg)
                : round($rawAvg, 1);
        }

        return [
            'avg' => $avg,
            'sla_percent' => $total === 0 ? 100 : (int) round(($slaMet / $total) * 100),
            'sla_met' => $slaMet,
            'sla_total' => $total,
        ];
    }

    /**
     * @param  array{start: string, end: string}  $shift
     */
    private function rowWorkMinutes(object $row, array $shift): ?int
    {
        $receivedAt = $row->received_at
            ? Carbon::parse($row->received_at)
            : ($row->date_of_received ? Carbon::parse($row->date_of_received)->startOfDay() : null);
        if ($receivedAt === null || empty($row->contract_created_at)) {
            return null;
        }

        return ContractReceivedTiming::workMinutesBetween(
            Carbon::parse($row->contract_created_at),
            $receivedAt,
            $shift
        );
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{avg: float|null}
     */
    private function processTimeStats(Collection $rows): array
    {
        $minutes = [];

        foreach ($rows as $row) {
            $receivedAt = $row->received_at
                ? Carbon::parse($row->received_at)
                : ($row->date_of_received ? Carbon::parse($row->date_of_received)->startOfDay() : null);
            if ($receivedAt === null || empty($row->completed_at)) {
                continue;
            }

            $minutes[] = ContractReceivedTiming::minutesBetween(
                $receivedAt,
                Carbon::parse($row->completed_at)
            );
        }

        return [
            'avg' => $this->averageOrNull($minutes),
        ];
    }

    /**
     * @param  list<int|float>  $values
     */
    private function averageOrNull(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        $rawAvg = array_sum($values) / count($values);

        return abs($rawAvg - round($rawAvg)) < 0.05
            ? (float) (int) round($rawAvg)
            : round($rawAvg, 1);
    }

    private function moneyValue(float $amount): int|float
    {
        $rounded = round($amount, 2);

        return abs($rounded - (int) round($rounded)) < 0.005
            ? (int) round($rounded)
            : $rounded;
    }

    private function queryString(Request $request, string $key): ?string
    {
        $raw = $request->query($key);
        if ($raw === null) {
            return null;
        }

        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    private function parseDateBoundary(string $value, bool $endOfDay): Carbon
    {
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $date = Carbon::createFromFormat('Y-m-d', $value);
                if (! $date instanceof Carbon || $date->format('Y-m-d') !== $value) {
                    throw new InvalidArgumentException('صيغة التاريخ يجب أن تكون YYYY-MM-DD.');
                }
            } else {
                $date = Carbon::parse($value);
            }
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new InvalidArgumentException('صيغة التاريخ يجب أن تكون YYYY-MM-DD.');
        }

        if (! $date instanceof Carbon) {
            throw new InvalidArgumentException('صيغة التاريخ يجب أن تكون YYYY-MM-DD.');
        }

        return $endOfDay ? $date->copy()->endOfDay() : $date->copy()->startOfDay();
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return array<string, mixed>
     */
    private function receivedOrdersTable(Employee $employee, string $periodLabel, ?array $range): array
    {
        $shift = $this->shiftFor($employee);
        $sla = (int) config('employee_kpis.receive_sla_minutes', 5);

        $query = ReceivedContract::query()
            ->with(['contract.contractStatus'])
            ->where('employee_id', $employee->id)
            ->whereHas('contract', fn ($q) => $q->where('is_delete', 0));

        $this->applyReceivedAtRange($query, $range);

        $rows = $query->orderByDesc('created_at')->get();
        $now = now();
        $items = [];

        foreach ($rows as $received) {
            $contract = $received->contract;
            if ($contract === null) {
                continue;
            }

            $receivedAt = ContractReceivedTiming::receivedAt($received);
            $createdAt = $contract->created_at ? Carbon::parse($contract->created_at) : null;
            $workMinutes = ($receivedAt !== null && $createdAt !== null)
                ? ContractReceivedTiming::workMinutesBetween($createdAt, $receivedAt, $shift)
                : null;
            $slaMet = $workMinutes === null || $workMinutes <= $sla;
            $ageMinutes = $receivedAt !== null
                ? ContractReceivedTiming::minutesBetween($receivedAt, $now)
                : null;
            $status = $contract->contractStatus;

            $items[] = [
                'id' => (int) $contract->id,
                'order' => '#'.$contract->id,
                'contract_id' => (int) $contract->id,
                'uuid' => $contract->uuid,
                'received_at' => $receivedAt?->format('Y-m-d H:i:s'),
                'received_at_label' => $receivedAt?->format('d/m/Y H:i:s'),
                'receive_work_minutes' => $workMinutes,
                'receive_work_minutes_label' => $workMinutes === null ? '—' : (string) $workMinutes,
                'sla_met' => $slaMet,
                'sla' => $workMinutes === null ? 'na' : ($slaMet ? 'pass' : 'fail'),
                'sla_label_ar' => $workMinutes === null ? '—' : ($slaMet ? 'ضمن ≤'.$sla.'د' : 'تجاوز'),
                'current_status_id' => $status?->id ? (int) $status->id : (int) $contract->contract_status_id,
                'current_status' => $status?->name,
                'current_status_color' => $status?->color,
                'age_since_received_minutes' => $ageMinutes,
                'age_since_received_label' => $ageMinutes === null ? '—' : ContractReceivedTiming::durationPhrase($ageMinutes),
            ];
        }

        return [
            'label_ar' => 'الطلبات المستلمة ('.$periodLabel.')',
            'count' => count($items),
            'count_label_ar' => count($items).' طلب',
            'columns' => [
                ['key' => 'order', 'label_ar' => 'الطلب'],
                ['key' => 'received_at', 'label_ar' => 'استُلم في'],
                ['key' => 'receive_work_minutes', 'label_ar' => 'زمن الاستلام (د عمل)'],
                ['key' => 'sla', 'label_ar' => 'SLA'],
                ['key' => 'current_status', 'label_ar' => 'الحالة الآن'],
                ['key' => 'age_since_received', 'label_ar' => 'عمره منذ الاستلام'],
            ],
            'items' => $items,
        ];
    }

    /**
     * @return array{name: string, start: string, end: string}
     */
    private function shiftFor(Employee $employee): array
    {
        $period = $employee->resolvedWorkPeriod();
        $config = (array) (config("employee_kpis.work_periods.{$period}") ?: []);
        $default = config('employee_kpis.default_shift', [
            'name' => 'وردية الصباح',
            'start' => '09:00',
            'end' => '17:00',
        ]);

        return [
            'name' => (string) ($config['shift_name'] ?? $employee->shift_name ?? $default['name']),
            'start' => (string) ($config['start'] ?? $employee->shift_start ?? $default['start']),
            'end' => (string) ($config['end'] ?? $employee->shift_end ?? $default['end']),
        ];
    }

    /**
     * @param  array{start: string, end: string}  $shift
     */
    private function isOnDutyNow(array $shift): bool
    {
        return $this->isTimeWithinShift(now(), $shift);
    }

    /**
     * @param  array{start: string, end: string}  $shift
     */
    private function isTimeWithinShift(Carbon $time, array $shift): bool
    {
        $clock = $time->format('H:i');
        $start = $shift['start'];
        $end = $shift['end'];

        if ($start <= $end) {
            return $clock >= $start && $clock <= $end;
        }

        return $clock >= $start || $clock <= $end;
    }

    private function formatClockAr(string $hi): string
    {
        try {
            $time = Carbon::createFromFormat('H:i', $hi);
        } catch (\Throwable) {
            return $hi;
        }

        $hour = (int) $time->format('g');
        $minute = $time->format('i');
        $suffix = ((int) $time->format('H')) < 12 ? 'ص' : 'م';

        return $hour.':'.$minute.' '.$suffix;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activityForEmployee(Employee $employee, int $limit): array
    {
        $contractIds = ReceivedContract::query()
            ->where('employee_id', $employee->id)
            ->pluck('contract_id');

        if ($contractIds->isEmpty()) {
            return [];
        }

        $histories = ContractStatusHistory::query()
            ->whereIn('contract_id', $contractIds)
            ->orderByDesc('id')
            ->limit($limit * 2)
            ->get();

        $comments = ContractComment::query()
            ->where('employee_id', $employee->id)
            ->whereIn('contract_id', $contractIds)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $items = [];
        foreach ($histories as $row) {
            foreach ($this->historyToActivity($row, $employee->name) as $item) {
                $items[] = $item;
            }
        }

        foreach ($comments as $comment) {
            $at = $comment->created_at ? Carbon::parse($comment->created_at) : now();
            $items[] = [
                'occurred_at' => $at->format('Y-m-d H:i:s'),
                'occurred_at_label' => $at->format('d/m/Y H:i:s'),
                'contract_id' => (int) $comment->contract_id,
                'contract_number' => '#'.$comment->contract_id,
                'action' => 'comment',
                'title' => $employee->name.' أضاف تعليقاً',
                'details' => $comment->comment,
            ];
        }

        usort($items, static fn ($a, $b) => strcmp($b['occurred_at'], $a['occurred_at']));

        return array_slice($items, 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyToActivity(ContractStatusHistory $row, string $employeeName): array
    {
        $at = $row->created_at ? Carbon::parse($row->created_at) : now();
        $base = [
            'occurred_at' => $at->format('Y-m-d H:i:s'),
            'occurred_at_label' => $at->format('d/m/Y H:i:s'),
            'contract_id' => (int) $row->contract_id,
            'contract_number' => '#'.$row->contract_id,
        ];

        $meta = is_array($row->meta) ? $row->meta : [];
        $case = $meta['case'] ?? ContractStatusCase::resolve(
            $row->status_id ? (int) $row->status_id : null,
            $row->status_label
        );

        $items = [];

        if ($row->source === 'receive' || in_array(ContractStatus::keyForId($row->status_id ? (int) $row->status_id : null), [ContractStatus::KEY_RECEIVED, ContractStatus::KEY_RECEIVED_BY_EMPLOYEE], true)) {
            $items[] = $base + [
                'action' => 'received',
                'title' => $employeeName.' استلم الطلب',
                'details' => null,
            ];
        } else {
            $items[] = $base + [
                'action' => 'status_changed',
                'title' => 'تم تغيير الحالة إلى: '.$row->status_label,
                'details' => $this->statusDetails($meta, $case),
            ];
        }

        if ($case === ContractStatusCase::EJAR_AUTHENTICATION && (! empty($meta['deed_number']) || ! empty($meta['deed_addition_method']))) {
            $deedType = $this->deedTypeLabel((string) ($meta['deed_addition_method'] ?? $meta['deed_type'] ?? ''));
            $deedNumber = (string) ($meta['deed_number'] ?? '');
            $parts = [];
            if ($deedType !== '') {
                $parts[] = 'طريقة الإضافة: '.$deedType;
            }
            if ($deedNumber !== '') {
                $parts[] = 'رقم الصك: '.$deedNumber;
            }
            $items[] = $base + [
                'action' => 'property_update',
                'title' => 'تم رفع تحديث بيانات العقار'.($parts !== [] ? ' ('.implode(' | ', $parts).')' : ''),
                'details' => null,
            ];
        }

        if ($case === ContractStatusCase::SEND_DRAFT) {
            $items[] = $base + [
                'action' => 'client_confirmation',
                'title' => 'تم إرسال رسالة تأكيد للعميل',
                'details' => null,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function statusDetails(array $meta, ?string $case): ?string
    {
        $parts = [];

        if (! empty($meta['ejar_contract_number'])) {
            $parts[] = 'رقم عقد إيجار: '.$meta['ejar_contract_number'];
        }
        if (! empty($meta['ejar_contract_draft_number'])) {
            $parts[] = 'رقم المسودة: '.$meta['ejar_contract_draft_number'];
        }

        $notes = $meta['ejar_status_notes'] ?? $meta['notes'] ?? null;
        if ($case === ContractStatusCase::WAITING_SUPERVISOR || $notes !== null) {
            $parts[] = 'ملاحظة: '.(is_string($notes) && trim($notes) !== '' ? $notes : 'لا يوجد');
        }

        return $parts !== [] ? implode(' | ', $parts) : null;
    }

    private function deedTypeLabel(string $value): string
    {
        return match (ContractStatusCase::normalizeDeedType($value) ?? $value) {
            'paper' => 'ورقي',
            'electronic' => 'إلكتروني',
            'other' => 'أخرى',
            default => $value,
        };
    }
}
