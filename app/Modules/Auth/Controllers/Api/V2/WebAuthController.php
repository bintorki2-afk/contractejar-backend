<?php

namespace App\Modules\Auth\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Shared\Responses\Responser;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

/**
 * Website (SPA) authentication using email + password only — no SMS, no OTP.
 * Reuses the shared `users` table and Sanctum tokens, but is completely
 * independent of the mobile app's phone/OTP flow (AuthController), so neither
 * side affects the other. Email verification and password reset use Laravel's
 * built-in notifications + the password_reset_tokens table (free; cost is only
 * the email send, via the configured MAIL_* provider).
 */
class WebAuthController extends Controller
{
    use Responser;

    /** Register a new customer with email + password and email a verification link. */
    public function register(Request $request)
    {
        $data = $request->validate([
            'fname' => ['required', 'string', 'max:100'],
            'lname' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $user = User::create([
            'fname' => $data['fname'],
            'lname' => $data['lname'] ?? null,
            'email' => $data['email'],
            'password' => $data['password'], // hashed by the model cast
            'platform' => User::PLATFORM_WEBSITE,
            'is_active' => true,
        ]);

        // Best-effort: a mail misconfiguration must not fail the registration.
        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable) {
            // Swallow: the user can request a resend once mail is configured.
        }

        return $this->apiResponse(
            $this->authPayload($user),
            trans('api.web_register_success'),
            201,
        );
    }

    /** Email + password login — issues a Sanctum token. */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || $user->password === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [trans('api.web_credentials_error')],
            ]);
        }

        if (! $user->is_active) {
            return $this->errorMessage(trans('api.block_account'), 403);
        }

        return $this->apiResponse(
            $this->authPayload($user),
            trans('api.login_success'),
        );
    }

    /** Revoke the current access token. */
    public function logout(Request $request)
    {
        $token = $request->user()?->currentAccessToken();
        if ($token !== null) {
            $token->delete();
        }

        return $this->successMessage(trans('api.success'));
    }

    /** Email a password-reset link (always returns success to avoid user enumeration). */
    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            Password::sendResetLink(['email' => $data['email']]);
        } catch (\Throwable) {
            // Swallow provider errors; never reveal whether the email exists.
        }

        return $this->successMessage(trans('api.web_reset_link_sent'));
    }

    /** Complete a password reset using the emailed token. */
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->password = $password; // hashed by the model cast
            $user->save();
            $user->tokens()->delete(); // force re-login everywhere
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [trans($status)],
            ]);
        }

        return $this->successMessage(trans('api.web_reset_password_success'));
    }

    /** Resend the email verification link. */
    public function resendVerification(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user && ! $user->hasVerifiedEmail()) {
            try {
                $user->sendEmailVerificationNotification();
            } catch (\Throwable) {
                // Swallow provider errors.
            }
        }

        return $this->successMessage(trans('api.web_verification_resent'));
    }

    /**
     * Verify the email from the signed link in the verification email, then
     * redirect back to the website. Must match Laravel's signed-URL shape.
     */
    public function verifyEmail(Request $request, int $id, string $hash)
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()->away($frontend . '/verify-email?status=invalid');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect()->away($frontend . '/verify-email?status=success');
    }

    /** Current authenticated customer (used by the SPA after social login). */
    public function me(Request $request)
    {
        return $this->apiResponse(
            ['user' => new UserResource($request->user())],
            trans('api.success'),
        );
    }

    /** Begin a social sign-in (Google / Apple) — redirects to the provider. */
    public function socialRedirect(string $provider)
    {
        return Socialite::driver($provider)
            ->stateless()
            ->redirectUrl($this->socialCallbackUrl($provider))
            ->redirect();
    }

    /**
     * Social provider callback: find or create the customer, sign them in, and
     * hand the SPA a token via the URL fragment (never sent to servers).
     */
    public function socialCallback(string $provider)
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        try {
            $socialUser = Socialite::driver($provider)
                ->stateless()
                ->redirectUrl($this->socialCallbackUrl($provider))
                ->user();
        } catch (\Throwable) {
            return redirect()->away($frontend . '/login?error=social');
        }

        $email = $socialUser->getEmail();
        if (! $email) {
            return redirect()->away($frontend . '/login?error=social_no_email');
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            [$fname, $lname] = $this->splitName($socialUser->getName());
            $user = new User();
            $user->email = $email;
            $user->fname = $fname ?: ($socialUser->getNickname() ?: 'مستخدم');
            $user->lname = $lname;
            $user->password = Hash::make(Str::random(40)); // unusable; social-only
            $user->platform = User::PLATFORM_WEBSITE;
            $user->is_active = true;
        }

        // Social providers return a verified email; trust it.
        if (! $user->hasVerifiedEmail()) {
            $user->email_verified_at = now();
        }
        if ($provider === 'google') {
            $user->google_id = $socialUser->getId();
        }
        if (! $user->photo && $socialUser->getAvatar()) {
            $user->photo = $socialUser->getAvatar();
        }
        $user->save();

        if (! $user->is_active) {
            return redirect()->away($frontend . '/login?error=blocked');
        }

        $token = $user->createToken('website')->plainTextToken;

        return redirect()->away($frontend . '/auth/social-callback#token=' . $token);
    }

    private function socialCallbackUrl(string $provider): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/v2/auth/web/' . $provider . '/callback';
    }

    /** @return array{0: string, 1: ?string} */
    private function splitName(?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return ['', null];
        }

        $space = strpos($name, ' ');
        if ($space === false) {
            return [$name, null];
        }

        return [substr($name, 0, $space), trim(substr($name, $space + 1)) ?: null];
    }

    /** Shared auth payload: a fresh Sanctum token + the user resource. */
    private function authPayload(User $user): array
    {
        return [
            'token' => $user->createToken('website')->plainTextToken,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->fresh()),
            'email_verified' => $user->hasVerifiedEmail(),
        ];
    }
}
