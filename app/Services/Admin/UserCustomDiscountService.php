<?php

namespace App\Services\Admin;

use App\Models\Contract;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\CustomDiscount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserCustomDiscountService
{
    /**
     * Apply a custom discount or full waiver to one unpaid contract of this client.
     *
     * @param  array{contract_id:int, type:string, value?:mixed, reason:string}  $payload
     */
    public function apply(User $user, array $payload, ?int $employeeId = null): CustomDiscount
    {
        $contract = Contract::query()
            ->notDeleted()
            ->where('user_id', $user->id)
            ->whereKey((int) $payload['contract_id'])
            ->first();

        if (! $contract) {
            throw ValidationException::withMessages([
                'contract_id' => [trans('api.discount_contract_not_owned')],
            ]);
        }

        if ((int) $contract->is_completed === 1) {
            throw ValidationException::withMessages([
                'contract_id' => [trans('api.discount_contract_already_paid')],
            ]);
        }

        $uuid = (string) $contract->uuid;
        if ($uuid === '') {
            throw ValidationException::withMessages([
                'contract_id' => [trans('api.discount_contract_missing_uuid')],
            ]);
        }

        $type = (string) $payload['type'];
        $value = round((float) ($payload['value'] ?? 0), 2);
        $totalBefore = round((float) $contract->getPriceContractAttribute(), 2);

        if ($type === CustomDiscount::TYPE_PERCENTAGE && ($value <= 0 || $value > 100)) {
            throw ValidationException::withMessages([
                'value' => [trans('api.discount_percentage_invalid')],
            ]);
        }

        if ($type === CustomDiscount::TYPE_FIXED) {
            if ($value <= 0) {
                throw ValidationException::withMessages([
                    'value' => [trans('api.discount_fixed_invalid')],
                ]);
            }
            if ($totalBefore > 0 && $value > $totalBefore) {
                throw ValidationException::withMessages([
                    'value' => [trans('api.discount_exceeds_contract_total')],
                ]);
            }
        }

        if ($type === CustomDiscount::TYPE_WAIVER) {
            $value = 100;
        }

        $discountAmount = match ($type) {
            CustomDiscount::TYPE_PERCENTAGE, CustomDiscount::TYPE_WAIVER => round($totalBefore * $value / 100, 2),
            default => min($value, $totalBefore),
        };
        $totalAfter = max(0, round($totalBefore - $discountAmount, 2));

        $existingUsage = CouponUsage::query()->where('contract_uuid', $uuid)->first();
        if ($existingUsage) {
            $owned = CustomDiscount::query()
                ->where('contract_id', $contract->id)
                ->where('coupon_usage_id', $existingUsage->id)
                ->exists();
            if (! $owned) {
                throw ValidationException::withMessages([
                    'contract_id' => [trans('api.discount_coupon_already_applied')],
                ]);
            }
        }

        $discount = DB::transaction(function () use ($user, $contract, $uuid, $type, $value, $discountAmount, $totalBefore, $totalAfter, $payload, $employeeId, $existingUsage) {
            if ($existingUsage) {
                CustomDiscount::query()->where('coupon_usage_id', $existingUsage->id)->delete();
                $oldCouponId = $existingUsage->coupon_id;
                $existingUsage->delete();
                if ($oldCouponId) {
                    Coupon::query()->whereKey($oldCouponId)->delete();
                }
            }

            $couponType = $type === CustomDiscount::TYPE_FIXED ? 'value' : 'ratio';
            $couponValue = $type === CustomDiscount::TYPE_FIXED ? $discountAmount : $value;

            $coupon = Coupon::query()->create([
                'name' => 'خصم مخصص - العميل '.$user->id,
                'code_coupon' => 'AD'.strtoupper(Str::random(10)),
                'type_coupon' => $couponType,
                'value_coupon' => $couponValue,
                'date_start' => now()->toDateString(),
                'date_end' => now()->addYears(10)->toDateString(),
                'usage' => 1,
                'usage_of_user' => 1,
                'is_review' => true,
                'is_delete' => false,
            ]);

            $usage = CouponUsage::query()->create([
                'user_id' => $user->id,
                'coupon_id' => $coupon->id,
                'contract_uuid' => $uuid,
                'used_at' => now(),
            ]);

            $coupon->decrement('usage');

            return CustomDiscount::query()->create([
                'user_id' => $user->id,
                'contract_id' => $contract->id,
                'contract_uuid' => $uuid,
                'coupon_id' => $coupon->id,
                'coupon_usage_id' => $usage->id,
                'employee_id' => $employeeId,
                'type' => $type,
                'value' => $value,
                'discount_amount' => $discountAmount,
                'total_before' => $totalBefore,
                'total_after' => $totalAfter,
                'reason' => $payload['reason'],
            ]);
        });

        // دفعة (د) — ب9/ب10: سجل النشاط + إشعار العميل بالخصم.
        $employee = $employeeId ? \App\Models\Employee::query()->find($employeeId) : null;
        app(\App\Services\Orders\OrderFlowService::class)->activity(
            $contract, 'discount_applied', $employee,
            ['total' => $totalBefore],
            ['total' => $totalAfter, 'discount_amount' => $discountAmount, 'type' => $type, 'value' => $value, 'coupon_code' => $discount->coupon?->code_coupon],
            $employee ? 'employee' : 'system',
            (string) ($payload['reason'] ?? ''),
        );
        try {
            app(\App\Services\CustomerNotificationService::class)->discountApplied($contract, (float) $discountAmount, null, (float) $totalAfter);
        } catch (\Throwable $e) {
            report($e);
        }

        return $discount;
    }

    /**
     * الخصم المطبّق على الطلب (للّوحة): خصم مخصص من اللوحة أو كوبون استخدمه العميل.
     *
     * @return array<string, mixed>|null
     */
    public static function appliedFor(Contract $contract): ?array
    {
        $custom = CustomDiscount::query()->where('contract_id', $contract->id)->latest('id')->first();
        if ($custom) {
            return [
                'source' => 'custom_discount',
                'source_label' => 'خصم من اللوحة',
                'type' => $custom->type,
                'value' => (float) $custom->value,
                'amount' => (float) $custom->discount_amount,
                'total_before' => (float) $custom->total_before,
                'total_after' => (float) $custom->total_after,
                'coupon_code' => Coupon::query()->whereKey($custom->coupon_id)->value('code_coupon'),
                'reason' => $custom->reason,
                'employee_id' => $custom->employee_id,
                'employee_name' => $custom->employee_id ? \App\Models\Employee::query()->whereKey($custom->employee_id)->value('name') : null,
                'applied_at' => optional($custom->created_at)?->toIso8601String(),
            ];
        }

        if (! filled($contract->uuid)) {
            return null;
        }
        $usage = CouponUsage::query()->where('contract_uuid', (string) $contract->uuid)->latest('id')->first();
        if (! $usage) {
            return null;
        }
        $coupon = Coupon::query()->find($usage->coupon_id);

        return [
            'source' => 'coupon',
            'source_label' => 'كوبون العميل',
            'type' => $coupon?->type_coupon,
            'value' => $coupon ? (float) $coupon->value_coupon : null,
            'amount' => (float) (\App\Support\ContractPricing::for($contract)['coupon'] ?? 0),
            'coupon_code' => $coupon?->code_coupon,
            'reason' => null,
            'employee_id' => null,
            'employee_name' => null,
            'applied_at' => optional($usage->used_at ?? $usage->created_at)?->toIso8601String(),
        ];
    }
}
