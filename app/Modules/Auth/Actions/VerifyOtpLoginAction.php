<?php

namespace App\Modules\Auth\Actions;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Modules\Auth\Services\GuestAccountService;
use App\Modules\Auth\Services\UserOtpService;
use App\Modules\Auth\Support\AuthMobile;
use Illuminate\Http\Request;

/**
 * Passwordless phone login — step 2: verify the OTP and issue a token.
 *
 * Reuses UserOtpService::verify (consume = true, so the code is one-time) with
 * the same lockout/attempt rules as the existing verification flow. On success
 * the account is marked verified + a Sanctum token is returned — exactly the
 * token shape LoginUserAction returns, so the app's session handling is
 * unchanged. The password-based login endpoint stays intact and untouched.
 */
class VerifyOtpLoginAction
{
    public function __construct(private readonly UserOtpService $otp) {}

    /**
     * @return array{ok: true, result: array<string, mixed>}|array{ok: false, message: string, code?: int}
     */
    public function execute(Request $request): array
    {
        $user = User::whereIn('mobile', AuthMobile::lookupVariants($request->mobile))->first();

        if (! $user) {
            return ['ok' => false, 'message' => trans('api.credentials_error')];
        }

        $result = $this->otp->verify($user, UserOtpService::VERIFICATION, $request->verification_code, true);
        if (! $result['ok']) {
            return ['ok' => false, 'message' => $result['message'], 'code' => $result['code'] ?? 400];
        }

        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
        }
        if ($request->has('fcm_token')) {
            $user->fcm_token = $request->fcm_token;
        }
        $user->save();

        if (! $user->isActive()) {
            return ['ok' => false, 'message' => trans('api.block_account')];
        }

        $user->refresh();

        // دمج طلبات ضيوف الموقع الذين كتبوا هذا الرقم (ثبتت ملكيته الآن بالـ OTP).
        try {
            app(GuestAccountService::class)->mergeGuestsInto($user, (string) $request->mobile);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Guest merge after OTP failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'ok' => true,
            'result' => [
                'user' => new UserResource($user),
                'token' => $user->createToken('user_token')->plainTextToken,
            ],
        ];
    }
}
