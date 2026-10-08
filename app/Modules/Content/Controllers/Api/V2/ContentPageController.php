<?php

namespace App\Modules\Content\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Traits\Responser;
use App\Services\ContentPageService;
use Illuminate\Validation\ValidationException;

class ContentPageController extends Controller
{
    use Responser;

    public function __construct(private readonly ContentPageService $contentPages)
    {
    }

    public function index()
    {
        try {
            return $this->apiResponse(
                $this->contentPages->indexMeta(),
                'Page SEO fetched successfully'
            );
        } catch (\Throwable $e) {
            report($e);

            return $this->errorMessage(trans('api.error_occurred'), 500);
        }
    }

    public function show(string $pageKey)
    {
        try {
            $normalizedKey = $this->contentPages->normalizePageKey($pageKey);

            return $this->apiResponse(
                $this->contentPages->show($normalizedKey),
                $this->contentPages->fetchMessageFor($normalizedKey)
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (\Throwable $e) {
            report($e);

            return $this->errorMessage(trans('api.error_occurred'), 500);
        }
    }
}
