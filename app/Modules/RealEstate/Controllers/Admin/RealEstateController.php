<?php

namespace App\Modules\RealEstate\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\Responser;
use App\Http\Resources\RealEstateResource;
use App\Models\RealEstate;
use Illuminate\Http\Request;

class RealEstateController extends Controller
{
    use Responser;

    public function index(Request $request)
    {
        try {
            $query = RealEstate::query();

            // Filter by contract_type
            if ($request->filled('contract_type')) {
                $query->where('contract_type', $request->string('contract_type'));
            }

            $reals = $query
                ->latest()
                ->paginate($this->perPageFromRequest($request));

            return $this->paginatedApiResponse(
                $reals,
                RealEstateResource::collection($reals)
            );
        } catch (\Throwable $e) {
            return $this->errorMessage(
                trans('api.error_occurred') . ': ' . $e->getMessage(),
                500
            );
        }
    }

    public function show($id)
{
    $realEstate = RealEstate::with(['user', 'units'])->find($id);

    if (! $realEstate) {
        return $this->errorMessage(trans('api.not_found'), 404);
    }

    return $this->apiResponse(
        new RealEstateResource($realEstate),
        trans('api.success')
    );
}


    /** دفعة (و) — D6: GET /admin/real-estates/trash — محذوفات العملاء (عقارات + وحدات) خلال 30 يوماً. */
    public function trash(Request $request)
    {
        $userId = $request->filled('user_id') ? (int) $request->input('user_id') : null;

        return $this->apiResponse(
            app(\App\Services\RealEstate\PropertyTrashService::class)->listing($userId, withUser: true),
            trans('api.success')
        );
    }

    /** دفعة (و) — D6: POST /admin/real-estates/{id}/restore */
    public function restore(int $id)
    {
        $row = RealEstate::onlyTrashed()->find($id);
        if ($row === null) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }
        try {
            $row = app(\App\Services\RealEstate\PropertyTrashService::class)->restoreRealEstate($row);
        } catch (\InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        }

        return $this->apiResponse(['id' => $row->id, 'restored' => true], trans('api.success'));
    }

    /** دفعة (و) — D6: POST /admin/real-estates/units/{id}/restore */
    public function restoreUnit(int $id)
    {
        $row = \App\Models\UnitsReal::onlyTrashed()->find($id);
        if ($row === null) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }
        try {
            $row = app(\App\Services\RealEstate\PropertyTrashService::class)->restoreUnit($row);
        } catch (\InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 422);
        }

        return $this->apiResponse(['id' => $row->id, 'restored' => true], trans('api.success'));
    }
}
