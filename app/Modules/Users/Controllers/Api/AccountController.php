<?php

namespace App\Modules\Users\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use App\Modules\Users\Actions\DeactivateOwnAccountAction;
use App\Modules\Users\Actions\UpdateOwnPasswordAction;
use App\Modules\Users\Actions\UpdateOwnProfileAction;
use App\Modules\Users\Models\User;
use App\Modules\Users\Requests\Api\UpdateFcmTokenRequest;
use App\Modules\Users\Requests\Api\UpdatePasswordRequest;
use App\Modules\Users\Resources\UserResource;
use App\Shared\Responses\Responser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    use Responser;

    public function profile(Request $request)
    {
        $user = $this->authenticatedUser($request);

        if (! $user) {
            return $this->errorMessage(trans('api.unauthorized'), 401);
        }

        return $this->apiResponse(new UserResource($user), trans('api.success'));
    }

    public function deactivateUser(Request $request, DeactivateOwnAccountAction $action)
    {
        $outcome = $action->execute($this->authenticatedUser($request));

        if ($outcome['ok']) {
            return $this->successMessage(trans('api.success_remove'));
        }

        return $this->errorMessage($outcome['message']);
    }

    public function updateProfile(Request $request, UpdateOwnProfileAction $action)
    {
        $user = $this->authenticatedUser($request);

        if (! $user) {
            return $this->errorMessage(trans('api.unauthorized'), 401);
        }

        $request->validate([
            'fname' => 'nullable|string|max:255',
            'email' => 'nullable|email|unique:users,email,'.$user->id,
            'mobile' => 'nullable|string|max:20',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        $action->execute($user, $request);

        return $this->apiResponse(new UserResource($user), trans('api.success'));
    }

    public function updatePassword(UpdatePasswordRequest $request, UpdateOwnPasswordAction $action)
    {
        $user = $this->authenticatedUser($request);

        if (! $user) {
            return $this->errorMessage(trans('api.unauthorized'), 401);
        }

        if (! $action->execute(
            $user,
            (string) $request->validated('password'),
            (string) $request->validated('current_password')
        )) {
            return $this->errorMessage(trans('validation.current_password'), 422);
        }

        return $this->successMessage(trans('api.success'));
    }

    public function updateFCMToken(UpdateFcmTokenRequest $request)
    {
        $user = $this->authenticatedUser($request);

        if (! $user) {
            return $this->errorMessage(trans('api.unauthorized'), 401);
        }

        $token = (string) $request->fcm_token;

        // الرمز يخص جهازاً واحداً: انزعه من أي حساب آخر يحمله حتى لا يتشارك جهازان
        // نفس رمز الإشعارات (تسرّب إشعارات بين حسابين على نفس الجهاز).
        if ($token !== '') {
            User::query()
                ->where('fcm_token', $token)
                ->whereKeyNot($user->id)
                ->update(['fcm_token' => null]);
        }

        $user->update([
            'fcm_token' => $token !== '' ? $token : null,
        ]);

        return $this->successMessage(trans('api.success'));
    }

    /**
     * GET /api/v2/notifications?per_page=15&keep_unread=1
     *
     * السلوك القديم (متوافق مع التطبيق الحالي): الصفحة المعروضة تُعلَّم مقروءة بعد الرد.
     * العملاء الجدد يمرّرون `keep_unread=1` ويعلّمون القراءة صراحةً عبر
     * POST /notifications/{id}/read أو POST /notifications/read-all.
     */
    public function notifications(Request $request)
    {
        $user = $this->authenticatedUser($request);

        if (! $user) {
            return $this->errorMessage(trans('api.unauthorized'), 401);
        }

        $notifications = Offer::query()
            ->with(['contract:id,uuid', 'lessorChangeRequest:id,uuid'])
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhereNull('user_id');
            })
            ->orderByDesc('id')
            ->paginate($this->perPageFromRequest($request, 15, 50));

        $unreadCount = $this->unreadCountFor((int) $user->id);

        if (! $request->boolean('keep_unread')) {
            $notificationIds = $notifications->getCollection()->pluck('id')->all();

            dispatch(function () use ($notificationIds, $user) {
                if ($notificationIds === []) {
                    return;
                }

                Offer::query()
                    ->where('user_id', $user->id)
                    ->whereIn('id', $notificationIds)
                    ->where('is_read', false)
                    ->update(['is_read' => true, 'read_at' => now()]);
            })->afterResponse();
        }

        $hasItems = $notifications->total() > 0;

        return $this->apiResponse([
            'unread_notifications' => $unreadCount,
            'unread_count' => $unreadCount,
            'data' => $hasItems ? OfferResource::collection($notifications) : null,
            'pagination' => $hasItems ? $this->paginate($notifications) : null,
        ], trans('api.success'));
    }

    /** GET /api/v2/notifications/unread-count */
    public function unreadCount(Request $request)
    {
        $user = $this->authenticatedUser($request);

        if (! $user) {
            return $this->errorMessage(trans('api.unauthorized'), 401);
        }

        return $this->apiResponse([
            'unread_count' => $this->unreadCountFor((int) $user->id),
        ], trans('api.success'));
    }

    /** POST /api/v2/notifications/{id}/read */
    public function markNotificationRead(Request $request, int $id)
    {
        $user = $this->authenticatedUser($request);

        if (! $user) {
            return $this->errorMessage(trans('api.unauthorized'), 401);
        }

        $notification = Offer::query()
            ->whereKey($id)
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)->orWhereNull('user_id');
            })
            ->first();

        if (! $notification) {
            return $this->errorMessage(trans('api.not_found'), 404);
        }

        // الإشعارات العامة (بدون user_id) مشتركة بين الجميع ولا تُعلَّم لمستخدم بعينه.
        if ((int) $notification->user_id === (int) $user->id) {
            $notification->markRead();
        }

        return $this->apiResponse([
            'id' => $notification->id,
            'is_read' => true,
            'read_at' => optional($notification->fresh()->read_at)?->toIso8601String() ?? now()->toIso8601String(),
            'unread_count' => $this->unreadCountFor((int) $user->id),
        ], trans('api.success'));
    }

    /** POST /api/v2/notifications/read-all */
    public function markAllNotificationsRead(Request $request)
    {
        $user = $this->authenticatedUser($request);

        if (! $user) {
            return $this->errorMessage(trans('api.unauthorized'), 401);
        }

        $updated = Offer::query()
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return $this->apiResponse([
            'updated' => $updated,
            'unread_count' => 0,
        ], trans('api.success'));
    }

    private function unreadCountFor(int $userId): int
    {
        return Offer::query()
            ->where('user_id', $userId)
            ->where('is_read', false)
            ->count();
    }

    /**
     * Sanctum middleware authenticates the `sanctum` guard, not `api`.
     * `$request->user('api')` is often null even when a valid token is present.
     */
    private function authenticatedUser(Request $request): ?User
    {
        $user = $request->user() ?? Auth::user() ?? $request->user('api');

        if ($user instanceof User) {
            return $user;
        }

        if (is_object($user) && isset($user->id)) {
            return User::query()->find($user->id);
        }

        return null;
    }
}
