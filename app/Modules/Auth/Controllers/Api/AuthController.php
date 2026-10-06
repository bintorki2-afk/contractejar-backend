<?php

namespace App\Modules\Auth\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Actions\ConfirmResetPasswordCodeAction;
use App\Modules\Auth\Actions\ForgotPasswordAction;
use App\Modules\Auth\Actions\LoginUserAction;
use App\Modules\Auth\Actions\ResendVerificationAction;
use App\Modules\Auth\Actions\RequestOtpLoginAction;
use App\Modules\Auth\Actions\ResetUserPasswordAction;
use App\Modules\Auth\Actions\SignupUserAction;
use App\Modules\Auth\Actions\VerifyOtpLoginAction;
use App\Modules\Auth\Actions\VerifyUserAction;
use App\Modules\Auth\Requests\Api\ForgotPasswordRequest;
use App\Modules\Auth\Requests\Api\LoginUserRequest;
use App\Modules\Auth\Requests\Api\ResendVerificationRequest;
use App\Modules\Auth\Requests\Api\ResetPasswordCodeRequest;
use App\Modules\Auth\Requests\Api\ResetPasswordRequest;
use App\Modules\Auth\Requests\Api\SignupUserRequest;
use App\Modules\Auth\Requests\Api\VerificationRequest;
use App\Shared\Responses\Responser;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use Responser;

    public function login(LoginUserRequest $request, LoginUserAction $action)
    {
        $outcome = $action->execute($request);

        if (! $outcome['ok']) {
            return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
        }

        if (! empty($outcome['unverified'])) {
            return $this->apiResponse($outcome['result'], trans('api.unverified_account'));
        }

        return $this->apiResponse($outcome['result'], trans('api.login_success'));
    }

    public function signup(SignupUserRequest $request, SignupUserAction $action)
    {
        $outcome = $action->execute($request);

        if (! $outcome['ok']) {
            return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
        }

        return $this->apiResponse($outcome['user'], trans('api.success'));
    }

    public function verification(VerificationRequest $request, VerifyUserAction $action)
    {
        $outcome = $action->execute($request);

        if ($outcome['ok']) {
            return $this->successMessage(trans('api.verification_success'));
        }

        return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
    }

    public function resend(ResendVerificationRequest $request, ResendVerificationAction $action)
    {
        $outcome = $action->execute($request);

        if ($outcome['ok']) {
            return $this->successMessage(trans('api.send_otp_success'));
        }

        return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
    }

    /**
     * Passwordless login — step 1: send an OTP to the mobile number.
     * Creates a minimal account for a first-time number. No name/email/password.
     */
    public function requestOtp(Request $request, RequestOtpLoginAction $action)
    {
        $request->validate(['mobile' => ['required', 'string']]);

        $outcome = $action->execute($request);

        if ($outcome['ok']) {
            return $this->successMessage(trans('api.send_otp_success'));
        }

        return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
    }

    /**
     * Passwordless login — step 2: verify the OTP and return an auth token.
     */
    public function otpLogin(Request $request, VerifyOtpLoginAction $action)
    {
        $request->validate([
            'mobile' => ['required', 'string'],
            'verification_code' => ['required', 'string'],
        ]);

        $outcome = $action->execute($request);

        if (! $outcome['ok']) {
            return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
        }

        return $this->apiResponse($outcome['result'], trans('api.login_success'));
    }

    public function forgotPassword(ForgotPasswordRequest $request, ForgotPasswordAction $action)
    {
        $outcome = $action->execute($request);

        if ($outcome['ok']) {
            return $this->successMessage(trans('api.send_reset_password_code_success'));
        }

        return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
    }

    public function resetPasswordCode(ResetPasswordCodeRequest $request, ConfirmResetPasswordCodeAction $action)
    {
        $outcome = $action->execute($request);

        if ($outcome['ok']) {
            return $this->successMessage(trans('api.valid_code_to_reset_password'));
        }

        return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
    }

    public function resetPassword(ResetPasswordRequest $request, ResetUserPasswordAction $action)
    {
        $outcome = $action->execute($request);

        if ($outcome['ok']) {
            return $this->successMessage(trans('api.success'));
        }

        return $this->errorMessage($outcome['message'], $outcome['code'] ?? 400);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return $this->errorMessage(trans('api.unauthorized'), 401);
        }

        $user->tokens()->delete();

        return $this->successMessage(trans('api.logout_success'));
    }
}
