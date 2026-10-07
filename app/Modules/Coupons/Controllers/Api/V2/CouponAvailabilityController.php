<?php

namespace App\Modules\Coupons\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Shared\Responses\Responser;

/**
 * هل يوجد كوبون ساري الآن؟ (عام) — يحدد ظهور حقل «كود الخصم» في الموقع والتطبيق.
 * لا يكشف أي أكواد.
 */
class CouponAvailabilityController extends Controller
{
    use Responser;

    public function available()
    {
        $today = now()->startOfDay();

        $available = Coupon::query()
            ->where('is_delete', 0)
            ->where('is_review', true)
            ->whereDate('date_start', '<=', $today)
            ->whereDate('date_end', '>=', $today)
            ->where(fn ($q) => $q->whereNull('usage')->orWhere('usage', '>', 0))
            ->exists();

        return $this->apiResponse(['available' => $available], trans('api.success'));
    }
}
