<?php

namespace App\Modules\Users\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\V2\Api\CustomDiscountResource;
use App\Http\Resources\Admin\V2\Api\OrderResource;
use App\Http\Resources\Admin\V2\Api\UserCouponResource;
use App\Http\Resources\Api\V2\UnitResource;
use App\Http\Resources\RealEstateResource;
use App\Models\RealEstate;
use App\Models\UserCoupon;
use App\Modules\Users\Actions\DeleteUserAction;
use App\Modules\Users\Actions\DestroyUserPropertyAction;
use App\Modules\Users\Actions\DestroyUserUnitAction;
use App\Modules\Users\Actions\ExportUsersCsvAction;
use App\Modules\Users\Actions\ToggleUserActiveAction;
use App\Modules\Users\Models\User;
use App\Modules\Users\Requests\Admin\StoreUserCouponRequest;
use App\Modules\Users\Requests\Admin\StoreUserDiscountRequest;
use App\Modules\Users\Resources\Admin\AllUserResource;
use App\Modules\Users\Resources\Admin\UserPropertyResource;
use App\Modules\Users\Support\UsersDashboardQuery;
use App\Services\Admin\UserCouponService;
use App\Services\Admin\UserCustomDiscountService;
use App\Shared\Responses\Responser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    use Responser;

    public function __construct(private readonly UsersDashboardQuery $dashboard) {}

    public function allusers(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $usersQuery = $this->dashboard->make($request);

        $createdAtFilter = $request->query('created_at');
        if ($createdAtFilter) {
            $usersQuery = $usersQuery->when(
                in_array($createdAtFilter, ['today', 'week', 'month', 'year'], true),
                function ($query) use ($createdAtFilter) {
                    $now = now();
                    $ranges = [
                        'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
                        'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
                        'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
                        'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
                    ];
                    [$start, $end] = $ranges[$createdAtFilter] ?? [null, null];
                    if ($start && $end) {
                        $query->whereBetween('created_at', [$start, $end]);
                    }
                }
            );
        }

        $users = $usersQuery
            ->latest()
            ->paginate($this->perPageFromRequest($request, 25));

        return $this->paginatedApiResponse(
            $users,
            AllUserResource::collection($users),
            trans('api.success'),
            ['summary' => $this->dashboard->summary()]
        );
    }

    public function export(Request $request, ExportUsersCsvAction $action): StreamedResponse
    {
        $this->authorize('viewAny', User::class);

        return $action->execute($request);
    }

    public function show(Request $request, int $id)
    {
        $user = $this->dashboard->withTotals()
            ->tap(fn ($q) => $this->dashboard->applyDashboardRelations($q))
            ->find($id);

        if (! $user) {
            return $this->apiResponse(
                null,
                trans('api.user_not_found'),
                false,
                404
            );
        }

        $this->authorize('view', $user);
        $user->contracts->loadMissing(app(\App\Modules\Contracts\Services\AdminOrderQueryService::class)->orderListRelations());

        return $this->apiResponse(
            [
                'user' => new AllUserResource($user),
                'contracts' => OrderResource::collection($user->contracts),
                'real_estates' => RealEstateResource::collection($user->realEstate),
                'units' => UnitResource::collection($user->unitReal),
            ],
            trans('api.success')
        );
    }

    public function applyDiscount(StoreUserDiscountRequest $request, int $id, UserCustomDiscountService $service)
    {
        $user = User::query()->find($id);
        if (! $user) {
            return $this->errorMessage(trans('api.user_not_found'), 404);
        }

        $this->authorize('update', $user);

        try {
            $discount = $service->apply($user, $request->validated(), $request->user()?->id);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        }

        return $this->apiResponse(
            new CustomDiscountResource($discount),
            trans('api.discount_applied_successfully'),
            201
        );
    }

    public function storeCoupon(StoreUserCouponRequest $request, int $id, UserCouponService $service)
    {
        $user = User::query()->find($id);
        if (! $user) {
            return $this->errorMessage(trans('api.user_not_found'), 404);
        }

        $this->authorize('create', User::class);

        try {
            $coupon = $service->create($user, $request->validated(), $request->user()?->id);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        }

        return $this->apiResponse(
            new UserCouponResource($coupon),
            trans('api.user_coupon_created_successfully'),
            201
        );
    }

    public function coupons(int $id, UserCouponService $service)
    {
        $user = User::query()->find($id);
        if (! $user) {
            return $this->errorMessage(trans('api.user_not_found'), 404);
        }

        $this->authorize('view', $user);

        return $this->apiResponse(
            UserCouponResource::collection($service->listForUser($user)),
            trans('api.success')
        );
    }

    public function showCoupon(int $id, int $couponId)
    {
        $this->authorize('viewAny', User::class);

        $coupon = UserCoupon::query()
            ->with('coupon')
            ->where('user_id', $id)
            ->whereKey($couponId)
            ->first();

        if (! $coupon) {
            return $this->errorMessage(trans('api.user_coupon_not_found'), 404);
        }

        return $this->apiResponse(new UserCouponResource($coupon), trans('api.success'));
    }

    public function deactivateCoupon(int $id, int $couponId, UserCouponService $service)
    {
        $this->authorize('delete', User::class);

        $coupon = UserCoupon::query()
            ->with('coupon')
            ->where('user_id', $id)
            ->whereKey($couponId)
            ->first();

        if (! $coupon) {
            return $this->errorMessage(trans('api.user_coupon_not_found'), 404);
        }

        return $this->apiResponse(
            new UserCouponResource($service->deactivate($coupon)),
            trans('api.user_coupon_deactivated_successfully')
        );
    }

    public function properties(Request $request, int $id)
    {
        $user = User::query()->find($id);
        if (! $user) {
            return $this->errorMessage(trans('api.user_not_found'), 404);
        }

        $this->authorize('view', $user);

        $query = RealEstate::query()
            ->where('user_id', $id)
            ->with([
                'propertyType',
                'propertyUsages',
                'tenantEntityCity',
                'tenantEntityRegion',
                'contracts',
                'units.unitType',
                'units.unitUsage',
                'units.contracts',
                'units.linkedContracts',
            ])
            ->latest();

        if (Schema::hasColumn((new RealEstate)->getTable(), 'is_deleted')) {
            $query->where(function ($q) {
                $q->where('is_deleted', 0)->orWhereNull('is_deleted');
            });
        }

        $properties = $query->paginate($this->perPageFromRequest($request, 25));

        return $this->paginatedApiResponse(
            $properties,
            UserPropertyResource::collection($properties),
            trans('api.success')
        );
    }

    public function destroyProperty(int $id, int $propertyId, DestroyUserPropertyAction $action)
    {
        $this->authorize('delete', User::class);

        $outcome = $action->execute($id, $propertyId);

        if (! $outcome['ok']) {
            return $this->errorMessage($outcome['message'], $outcome['code']);
        }

        return $this->apiResponse([], trans('api.deleted_successfully'));
    }

    public function destroyUnit(int $id, int $unitId, DestroyUserUnitAction $action)
    {
        $this->authorize('delete', User::class);

        $outcome = $action->execute($id, $unitId);

        if (! $outcome['ok']) {
            return $this->errorMessage($outcome['message'], $outcome['code']);
        }

        return $this->apiResponse([], trans('api.deleted_successfully'));
    }

    public function downloadDeed(int $id, int $propertyId)
    {
        $this->authorize('viewAny', User::class);

        $property = RealEstate::query()
            ->where('user_id', $id)
            ->whereKey($propertyId)
            ->first();

        if (! $property) {
            return $this->errorMessage(trans('api.real_estate_not_found'), 404);
        }

        $relative = (string) $property->image_instrument;
        if ($relative === '') {
            return $this->errorMessage(trans('api.deed_not_found'), 404);
        }

        $fullPath = $this->resolvePublicFilePath($relative);
        if ($fullPath === null) {
            return redirect()->away(asset('storage/'.ltrim($relative, '/')));
        }

        $downloadName = 'deed-'.$property->id.'.'.pathinfo($fullPath, PATHINFO_EXTENSION);

        return response()->download($fullPath, $downloadName);
    }

    public function newcommersUser(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $users = $this->dashboard->make($request)
            ->whereDate('created_at', now()->toDateString())
            ->latest()
            ->paginate($this->perPageFromRequest($request, 25));

        return $this->paginatedApiResponse(
            $users,
            AllUserResource::collection($users),
            trans('api.success'),
            ['summary' => $this->dashboard->summary()]
        );
    }

    public function usersCompleteContracts()
    {
        $this->authorize('viewAny', User::class);

        $users = $this->dashboard->make(request())
            ->whereHas('contracts', function ($q) {
                $q->where('is_completed', 1);
            })
            ->orderBy('updated_at', 'asc')
            ->get();

        return $this->apiResponse(
            AllUserResource::collection($users),
            trans('api.success')
        );
    }

    public function block($id, ToggleUserActiveAction $action)
    {
        $this->authorize('update', User::class);

        $outcome = $action->execute($id);

        if (! $outcome) {
            return $this->apiResponse(
                [],
                trans('api.user_not_found'),
                404
            );
        }

        $user = $outcome['user'];

        return $this->apiResponse(
            [
                'is_active' => (bool) $user->is_active,
                'status' => (bool) $user->is_active,
            ],
            $outcome['was_active']
                ? trans('api.user_blocked_successfull')
                : trans('api.user_unblocked_successfull')
        );
    }

    public function deleteUser($id, DeleteUserAction $action)
    {
        $this->authorize('delete', User::class);

        if ($action->execute($id)) {
            return $this->apiResponse(
                [],
                trans('api.user_deleted_successfull')
            );
        }

        return $this->apiResponse(
            [],
            trans('api.user_not_found'),
            404
        );
    }

    private function resolvePublicFilePath(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $path = preg_replace('#^storage/#', '', $path) ?? $path;

        foreach ([
            storage_path('app/public/'.$path),
            public_path('storage/'.$path),
            public_path($path),
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
