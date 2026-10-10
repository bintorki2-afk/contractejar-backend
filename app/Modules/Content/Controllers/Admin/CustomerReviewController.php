<?php

namespace App\Modules\Content\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\Responser;
use App\Models\CustomerReview;
use App\Models\Setting;
use App\Support\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * دفعة (و) — D7: إدارة التقييمات المعروضة + ملخص «4.7 من 3000» (قسم «التسويق والمحتوى»).
 * صلاحيات القسم customer_reviews (view/create/edit/delete).
 */
class CustomerReviewController extends Controller
{
    use Responser;

    public function index(Request $request)
    {
        $query = CustomerReview::query()->ordered();
        if ($request->filled('search')) {
            $term = '%'.trim((string) $request->query('search')).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('city', 'like', $term)->orWhere('text', 'like', $term));
        }
        if ($request->has('visible') && $request->query('visible') !== '') {
            $query->where('is_visible', $request->boolean('visible'));
        }

        return $this->apiResponse([
            'summary' => Setting::reviewsSummary(),
            'reviews' => $query->get()->map(fn (CustomerReview $r) => $r->toAdminArray())->values()->all(),
        ], trans('api.success'));
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate($this->rules(true), $this->messages());
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        }
        if (! array_key_exists('sort_order', $data) || $data['sort_order'] === null) {
            $data['sort_order'] = (int) CustomerReview::query()->max('sort_order') + 1;
        }
        $data['is_visible'] = array_key_exists('is_visible', $data) ? (bool) $data['is_visible'] : true;
        $review = CustomerReview::query()->create($data);

        return $this->apiResponse($review->toAdminArray(), trans('api.created_successfully'), true, 201);
    }

    public function update(Request $request, int $id)
    {
        $review = CustomerReview::query()->find($id);
        if ($review === null) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }
        try {
            $data = $request->validate($this->rules(false), $this->messages());
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        }
        foreach (['sort_order', 'is_visible', 'rating'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                unset($data[$k]);
            }
        }
        $review->update($data);

        return $this->apiResponse($review->fresh()->toAdminArray(), trans('api.updated_successfully'));
    }

    public function destroy(int $id)
    {
        $review = CustomerReview::query()->find($id);
        if ($review === null) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }
        $review->delete();

        return $this->apiResponse(['id' => $id], trans('api.deleted_successfully'));
    }

    /** POST /admin/customer-reviews/reorder {ids: [...]} — الترتيب الجديد بالتسلسل. */
    public function reorder(Request $request)
    {
        try {
            $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct']]);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        }
        foreach (array_values($data['ids']) as $i => $id) {
            CustomerReview::query()->whereKey($id)->update(['sort_order' => $i + 1]);
        }
        PublicCache::flush();

        return $this->index($request->duplicate([]));
    }

    public function settings()
    {
        return $this->apiResponse($this->settingsPayload(), trans('api.success'));
    }

    public function updateSettings(Request $request)
    {
        try {
            $data = $request->validate([
                'reviews_enabled' => ['nullable', 'boolean'],
                'reviews_average' => ['nullable', 'numeric', 'min:0', 'max:5'],
                'reviews_count' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            ], [
                'reviews_average.max' => 'متوسط التقييم يجب ألا يتجاوز 5.',
                'reviews_average.min' => 'متوسط التقييم لا يكون سالباً.',
                'reviews_count.integer' => 'عدد التقييمات يجب أن يكون رقماً صحيحاً.',
            ]);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        }
        $data = array_filter($data, static fn ($v) => $v !== null);
        $setting = Setting::query()->first() ?? Setting::query()->create([]);
        if ($data !== []) {
            $setting->forceFill($data)->save();
        }
        PublicCache::flush();

        return $this->apiResponse($this->settingsPayload($setting->fresh()), trans('api.updated_successfully'));
    }

    /** @return array<string, mixed> */
    private function settingsPayload(?Setting $setting = null): array
    {
        $summary = Setting::reviewsSummary($setting);

        return [
            'reviews_enabled' => $summary['enabled'],
            'reviews_average' => $summary['average'],
            'reviews_count' => $summary['count'],
            'label' => $summary['label'],
        ];
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'text' => [$req, 'string', 'max:2000'],
            'rating' => [$req, 'integer', 'min:1', 'max:5'],
            'contract_type' => ['nullable', Rule::in(CustomerReview::CONTRACT_TYPES)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_visible' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'name.required' => 'اسم العميل مطلوب.',
            'text.required' => 'نص التقييم مطلوب.',
            'rating.required' => 'عدد النجوم مطلوب.',
            'rating.min' => 'النجوم من 1 إلى 5.',
            'rating.max' => 'النجوم من 1 إلى 5.',
            'contract_type.in' => 'نوع العقد: سكني أو تجاري.',
        ];
    }
}
