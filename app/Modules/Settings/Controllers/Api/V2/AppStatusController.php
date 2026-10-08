<?php

namespace App\Modules\Settings\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Traits\Responser;
use App\Services\AppStatusService;
use Illuminate\Http\Request;

class AppStatusController extends Controller
{
    use Responser;

    public function __construct(protected AppStatusService $appStatus)
    {
    }

    /**
     * GET /api/v2/app-status
     * GET /api/v2/app-status?platform=ios&current_version=1.0.3
     *
     * Call on splash. If website/mobile is_open is false, show maintenance.
     * If update.force_update is true, block and send the user to store_url.
     */
    public function show(Request $request)
    {
        $platform = $request->query('platform');
        $currentVersion = $request->query('current_version', $request->query('version'));

        return $this->apiResponse(
            $this->appStatus->publicPayload(
                is_string($platform) ? $platform : null,
                is_string($currentVersion) ? $currentVersion : null
            ),
            trans('api.success')
        );
    }

    /**
     * GET /api/v2/app/version — عام (بدون مصادقة).
     *
     * { ios: {min_version, latest_version, store_url, force_update}, android: {...}, force_update_message }
     * التطبيق يقارن إصداره مع min_version ويعرض شاشة تحديث إجباري عند الحاجة.
     */
    public function version()
    {
        return $this->apiResponse($this->appStatus->versionPayload(), trans('api.success'))
            ->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * GET /api/v2/website-status
     * GET /api/website-status
     *
     * Website SPA: if is_open is false, show the closed screen.
     */
    public function website()
    {
        $payload = $this->appStatus->websitePayload();

        if ($payload['is_open']) {
            return $this->apiResponse($payload, trans('api.success'));
        }

        return $this->jsonResponse([
            'message' => trans('api.website_closed'),
            'code' => 503,
            'success' => false,
            'data' => $payload,
        ], 503);
    }
}
