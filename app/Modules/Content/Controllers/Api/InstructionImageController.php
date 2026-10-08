<?php

namespace App\Modules\Content\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InstructionSectionImageClientResource;
use App\Http\Traits\Responser;
use App\Models\InstructionSection;
use Illuminate\Http\Request;

/**
 * Mobile/client API: resolve instructional images by section key (no static URLs).
 */
class InstructionImageController extends Controller
{
    use Responser;

    /**
     * GET /api/instruction-images/{key}
     */
    public function show(string $key)
    {
        try {
            $section = InstructionSection::query()
                ->where('key', $key)
                ->where('is_active', true)
                ->with(['images' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
                ->first();

            if (! $section) {
                return $this->apiResponse([
                    'key' => $key,
                    'images' => [],
                ], trans('api.success'));
            }

            return $this->apiResponse([
                'key' => $section->key,
                'title_ar' => $section->title_ar,
                'images' => InstructionSectionImageClientResource::collection($section->images),
            ], trans('api.success'));
        } catch (\Throwable $e) {
            report($e);

            return $this->errorMessage(trans('api.error_occurred'), 500);
        }
    }

    /**
     * GET /api/instruction-images?keys=new-client,start
     * Optional comma-separated filter; returns only active sections.
     */
    public function index(Request $request)
    {
        try {
            $query = InstructionSection::query()
                ->where('is_active', true)
                ->with(['images' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
                ->orderBy('sort_order')
                ->orderBy('id');

            if ($request->filled('keys')) {
                $keys = array_filter(array_map('trim', explode(',', $request->string('keys'))));
                if ($keys !== []) {
                    $query->whereIn('key', $keys);
                }
            }

            $sections = $query->get();

            $items = $sections->map(fn (InstructionSection $section) => [
                'key' => $section->key,
                'title_ar' => $section->title_ar,
                'images' => InstructionSectionImageClientResource::collection($section->images),
            ])->values();

            return $this->apiResponse(['items' => $items], trans('api.success'));
        } catch (\Throwable $e) {
            report($e);

            return $this->errorMessage(trans('api.error_occurred'), 500);
        }
    }
}
