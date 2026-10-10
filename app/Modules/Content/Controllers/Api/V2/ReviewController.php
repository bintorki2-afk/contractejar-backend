<?php

namespace App\Modules\Content\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Traits\Responser;
use App\Models\CustomerReview;
use App\Models\Setting;
use App\Support\PublicCache;
use Illuminate\Http\Request;

/**
 * دفعة (و) — D7: GET /api/v2/reviews — ملخص التقييمات + القائمة الظاهرة (عام، مع كاش).
 */
class ReviewController extends Controller
{
    use Responser;

    public function index(Request $request)
    {
        $limit = max(1, min(200, (int) $request->query('limit', 50)));
        $payload = PublicCache::remember(PublicCache::KEY_REVIEWS, fn () => [
            'summary' => Setting::reviewsSummary(),
            'reviews' => CustomerReview::query()->visible()->ordered()->limit(200)->get()
                ->map(fn (CustomerReview $r) => $r->toPublicArray())->values()->all(),
        ]);
        $payload['reviews'] = array_slice($payload['reviews'], 0, $limit);

        return $this->apiResponse($payload, trans('api.success'))
            ->header('Cache-Control', PublicCache::CACHE_CONTROL);
    }
}
