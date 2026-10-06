<?php

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Auth\Services\UserOtpService;
use App\Modules\Auth\Support\AuthMobile;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Passwordless phone login — step 1: request an OTP.
 *
 * Mirrors SignupUserAction but collects NOTHING except the mobile number:
 * if the number has no account yet, a minimal account is created (random
 * password, no name/email) so the same flow serves both new and returning
 * users. Response is intentionally generic (never reveals whether the number
 * already exists). Reuses the shared UserOtpService (hashing, TTL, rate limit,
 * lockout) and the Taqnyat/log SMS driver — no new infrastructure.
 */
class RequestOtpLoginAction
{
    public function __construct(
        private readonly SendUserAuthSmsAction $sms,
        private readonly UserOtpService $otp,
    ) {}

    /**
     * @return array{ok: true}|array{ok: false, message: string, code?: int}
     */
    public function execute(Request $request): array
    {
        $otpType = 'otp_login';
        $formattedMobile = AuthMobile::normalizeSaudiMobile($request->mobile);

        $user = User::whereIn('mobile', AuthMobile::lookupVariants($request->mobile))->first();

        $blocked = $this->otp->assertCanSend($user, UserOtpService::VERIFICATION, $formattedMobile);
        if ($blocked !== null) {
            $this->sms->logBlockedResend($user?->id, $formattedMobile, $otpType);

            return $blocked;
        }

        if (! $user) {
            $data = ['mobile' => $formattedMobile, 'password' => bcrypt(Str::random(40))];
            if ($request->filled('platform')) {
                $data['platform'] = User::normalizePlatform((string) $request->input('platform'));
            }
            $user = User::create($data);
        }

        $plain = $this->otp->issue($user, UserOtpService::VERIFICATION);

        $smsResult = $this->sms->sendOtp(
            $this->otp->smsBody(UserOtpService::VERIFICATION, $plain),
            $user->mobile,
            $otpType,
            $user->id
        );

        if ($smsResult === true) {
            return ['ok' => true];
        }

        return ['ok' => false, 'message' => $smsResult ?: trans('api.error_sending_sms')];
    }
}
