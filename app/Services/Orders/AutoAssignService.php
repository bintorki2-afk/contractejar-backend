<?php

namespace App\Services\Orders;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\Employee;
use App\Models\ReceivedContract;
use App\Models\Setting;
use App\Services\FirebaseNotificationService;
use App\Support\SchemaCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * الإسناد التلقائي للطلبات المدفوعة (دفعة د — ب13).
 *
 * المؤهَّلون: موظفون نشطون لديهم صلاحية استلام الطلبات (`all_requests.edit` — نفس صلاحية زر «استلمت»)،
 * ليسوا مدراء نظام، واختيارياً ضمن قائمة `auto_assign_employee_ids` المحددة من الإعدادات.
 * الاستراتيجية: round_robin (بالدور) أو least_load (الأقل طلبات مفتوحة).
 */
class AutoAssignService
{
    public const STRATEGIES = ['round_robin', 'least_load'];

    public const RECEIVE_PERMISSION = 'all_requests.edit';

    public function __construct(private readonly OrderFlowService $flow) {}

    public function enabled(?Setting $setting = null): bool
    {
        $setting ??= Setting::query()->first();

        return $setting !== null && SchemaCache::hasColumn('settings', 'auto_assign_orders') && (bool) $setting->auto_assign_orders;
    }

    /**
     * يسند الطلب إن كان الإسناد مفعّلاً. يرجع الموظف المُسند أو null.
     */
    public function assign(Contract $contract): ?Employee
    {
        $setting = Setting::query()->first();
        if (! $this->enabled($setting)) {
            return null;
        }
        if (ReceivedContract::query()->where('contract_id', $contract->id)->exists()) {
            return null;
        }

        return Cache::lock('auto-assign-orders', 10)->block(5, function () use ($contract, $setting) {
            $employee = $this->pick($setting);
            if ($employee === null) {
                Log::info('auto-assign: no eligible employee', ['contract_id' => $contract->id]);

                return null;
            }

            $result = $this->flow->receive($contract->fresh(), $employee, ['notes' => 'إسناد تلقائي'], 'auto_assign');
            if (! $result['ok']) {
                return null;
            }

            $setting->forceFill(['auto_assign_last_employee_id' => $employee->id])->save();

            try {
                app(FirebaseNotificationService::class)->sendToEmployee(
                    (int) $employee->id,
                    'طلب جديد مُسند لك',
                    'أُسند لك الطلب رقم '.$contract->uuid.' تلقائياً',
                    ['type' => 'order_assigned', 'contract_id' => (string) $contract->id, 'contract_uuid' => (string) $contract->uuid]
                );
            } catch (\Throwable $e) {
                report($e);
            }

            return $employee;
        });
    }

    /**
     * @return Collection<int, Employee>
     */
    public function eligible(?Setting $setting = null): Collection
    {
        $setting ??= Setting::query()->first();
        $pool = is_array($setting?->auto_assign_employee_ids) ? array_map('intval', $setting->auto_assign_employee_ids) : [];

        return Employee::query()
            ->where('is_active', true)
            ->when($pool !== [], fn ($q) => $q->whereIn('id', $pool))
            ->orderBy('id')
            ->get()
            ->filter(fn (Employee $e) => ($pool !== [] || ! $e->isSystemAdmin()) && $e->hasPermission(self::RECEIVE_PERMISSION))
            ->values();
    }

    public function pick(?Setting $setting = null): ?Employee
    {
        $setting ??= Setting::query()->first();
        $candidates = $this->eligible($setting);
        if ($candidates->isEmpty()) {
            return null;
        }

        $strategy = in_array($setting?->auto_assign_strategy, self::STRATEGIES, true) ? $setting->auto_assign_strategy : 'round_robin';

        if ($strategy === 'least_load') {
            $closed = ContractStatus::idsFor([
                ContractStatus::KEY_EJAR_AUTHENTICATED, ContractStatus::KEY_COMPLETED,
                ContractStatus::KEY_CANCELLED, ContractStatus::KEY_REFUNDED,
            ]);
            $loads = ReceivedContract::query()
                ->whereIn('employee_id', $candidates->pluck('id'))
                ->whereHas('contract', fn ($q) => $q->where('is_delete', 0)
                    ->when($closed !== [], fn ($w) => $w->where(fn ($x) => $x->whereNull('contract_status_id')->orWhereNotIn('contract_status_id', $closed))))
                ->selectRaw('employee_id, COUNT(*) as c')
                ->groupBy('employee_id')
                ->pluck('c', 'employee_id');

            return $candidates->sortBy(fn (Employee $e) => sprintf('%08d-%08d', (int) ($loads[$e->id] ?? 0), $e->id))->first();
        }

        $last = (int) ($setting?->auto_assign_last_employee_id ?? 0);

        return $candidates->first(fn (Employee $e) => $e->id > $last) ?? $candidates->first();
    }
}
