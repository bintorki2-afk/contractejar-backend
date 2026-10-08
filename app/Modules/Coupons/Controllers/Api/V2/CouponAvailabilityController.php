<?php

namespace App\Modules\Coupons\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Shared\Responses\Responser;
use App\Support\PublicCache;

/**
 * هل يوجد كوبون ساري الآن؟ (عام) — يحدد ظهور حقل «كود الخصم» في الموقع والتطبيق.
 * لا يكشف أي أكواد.
 */
class CouponAvailabilityController extends Controller
{
    use Responser;

    public function available()
    {
        $available = PublicCache::remember(PublicCache::KEY_COUPON_AVAILABLE, static function (): bool {
            $today = now()->startOfDay();

            return Coupon::query()
                ->where('is_delete', 0)
                ->where('is_review', true)
                ->whereDate('date_start', '<=', $today)
                ->whereDate('date_end', '>=', $today)
                ->where(fn ($q) => $q->whereNull('usage')->orWhere('usage', '>', 0))
                ->exists();
        });

        return $this->apiResponse(['available' => (bool) $available], trans('api.success'))
            ->header('Cache-Control', PublicCache::CACHE_CONTROL);
    }
}
