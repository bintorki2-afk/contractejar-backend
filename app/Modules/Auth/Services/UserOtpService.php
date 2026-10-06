<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Support\AuthMobile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

class UserOtpService
{
    public const VERIFICATION = 'verification';

    public const RESET = 'reset';

    /**
     * @return array{ok: false, message: string, code: int}|null
     */
    public function assertCanSend(?User $user, string $purpose, string $mobile): ?array
    {
        $this->assertPurpose($purpose);

        if ($user !== null) {
            $locked = $this->lockedOutcome($user, $purpose);
            if ($locked !== null) {
                return $locked;
            }
        }

        $normalized = AuthMobile::normalizeSaudiMobile($mobile) ?: $mobile;

        if (RateLimiter::tooManyAttempts($this->sendCooldownKey($normalized), 1)) {
            return [
                'ok' => false,
                'message' => trans('api.otp_wait_before_resend'),
                'code' => 429,
            ];
        }

        $hourlyMax = max(1, (int) config('otp.send_max_per_hour', 5));
        if (RateLimiter::tooManyAttempts($this->sendHourlyKey($normalized), $hourlyMax)) {
            return [
                'ok' => false,
                'message' => trans('api.otp_send_rate_limited'),
                'code' => 429,
            ];
        }

        return null;
    }

    /**
     * Persist a hashed OTP and return the plaintext code for SMS delivery only.
     */
    public function issue(User $user, string $purpose): string
    {
        $this->assertPurpose($purpose);

        $plain = $this->generatePlain();
        $columns = $this->columns($purpose);
        $ttl = max(1, (int) config('otp.ttl_minutes', 10));

        $user->forceFill([
            $columns['hash'] => $this->hash($plain),
            $columns['expires'] => now()->addMinutes($ttl),
            $columns['attempts'] => 0,
            $columns['locked'] => null,
        ])->save();

        $normalized = AuthMobile::normalizeSaudiMobile((string) $user->mobile) ?: (string) $user->mobile;
        $cooldown = max(1, (int) config('otp.send_cooldown_seconds', 120));

        RateLimiter::hit($this->sendCooldownKey($normalized), $cooldown);
        RateLimiter::hit($this->sendHourlyKey($normalized), 3600);

        return $plain;
    }

    /**
     * @return array{ok: true}|array{ok: false, message: string, code?: int}
     */
    public function verify(User $user, string $purpose, ?string $plain, bool $consume = false): array
    {
        $this->assertPurpose($purpose);

        return DB::transaction(function () use ($user, $purpose, $plain, $consume) {
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $locked = $this->lockedOutcome($lockedUser, $purpose);
            if ($locked !== null) {
                return $locked;
            }

            $columns = $this->columns($purpose);
            $stored = (string) ($lockedUser->{$columns['hash']} ?? '');
            $expiresAt = $lockedUser->{$columns['expires']};

            if ($stored === '' || $expiresAt === null || now()->greaterThan($expiresAt)) {
                return [
                    'ok' => false,
                    'message' => trans('api.otp_expired'),
                    'code' => 400,
                ];
            }

            if (! $this->matches($stored, (string) $plain)) {
                return $this->registerFailure($lockedUser, $purpose);
            }

            $lockedUser->forceFill([
                $columns['attempts'] => 0,
                $columns['locked'] => null,
            ]);

            if ($consume) {
                $lockedUser->forceFill([
                    $columns['hash'] => null,
                    $columns['expires'] => null,
                ]);
            }

            $lockedUser->save();

            return ['ok' => true];
        });
    }

    public function consume(User $user, string $purpose): void
    {
        $this->assertPurpose($purpose);
        $columns = $this->columns($purpose);

        $user->forceFill([
            $columns['hash'] => null,
            $columns['expires'] => null,
            $columns['attempts'] => 0,
            $columns['locked'] => null,
        ])->save();
    }

    public function smsBody(string $purpose, string $plain): string
    {
        return $purpose === self::RESET
            ? 'الكود الخاص بتغير كلمة مرور حسابك في عقدي هو : '.$plain
            : 'كود تأكيد حسابك الخاص في عقدي هو: '.$plain;
    }

    public function hash(string $plain): string
    {
        return hash_hmac('sha256', $plain, (string) config('app.key'));
    }

    public function generatePlain(): string
    {
        // Temporary review mode: a fixed code via OTP_FIXED_CODE (see config/otp.php).
        $fixed = trim((string) config('otp.fixed_code', ''));
        if ($fixed !== '' && ctype_digit($fixed)) {
            return $fixed;
        }

        $length = max(4, min(8, (int) config('otp.length', 4)));
        $min = 10 ** ($length - 1);
        $max = (10 ** $length) - 1;

        return (string) random_int($min, $max);
    }

    /**
     * @return array{ok: false, message: string, code: int}|null
     */
    private function lockedOutcome(User $user, string $purpose): ?array
    {
        $columns = $this->columns($purpose);
        $until = $user->{$columns['locked']};

        if ($until === null || now()->greaterThanOrEqualTo($until)) {
            return null;
        }

        $seconds = $until->getTimestamp() - now()->getTimestamp();
        $minutes = max(1, (int) ceil($seconds / 60));

        return [
            'ok' => false,
            'message' => trans('api.otp_locked', ['minutes' => $minutes]),
            'code' => 429,
        ];
    }

    /**
     * @return array{ok: false, message: string, code: int}
     */
    private function registerFailure(User $user, string $purpose): array
    {
        $columns = $this->columns($purpose);
        $attempts = (int) $user->{$columns['attempts']} + 1;
        $maxAttempts = max(1, (int) config('otp.max_attempts', 5));
        $lockMinutes = max(1, (int) config('otp.lock_minutes', 15));

        $payload = [
            $columns['attempts'] => $attempts,
        ];

        if ($attempts >= $maxAttempts) {
            $payload[$columns['locked']] = now()->addMinutes($lockMinutes);
            $payload[$columns['hash']] = null;
            $payload[$columns['expires']] = null;

            $user->forceFill($payload)->save();

            return [
                'ok' => false,
                'message' => trans('api.otp_locked', ['minutes' => $lockMinutes]),
                'code' => 429,
            ];
        }

        $user->forceFill($payload)->save();

        $remaining = $maxAttempts - $attempts;

        return [
            'ok' => false,
            'message' => trans('api.otp_invalid_with_attempts', ['remaining' => $remaining]),
            'code' => 400,
        ];
    }

    private function matches(string $stored, string $plain): bool
    {
        if ($plain === '') {
            return false;
        }

        return hash_equals($stored, $this->hash($plain));
    }

    /**
     * @return array{hash: string, expires: string, attempts: string, locked: string}
     */
    private function columns(string $purpose): array
    {
        return match ($purpose) {
            self::VERIFICATION => [
                'hash' => 'verification_code',
                'expires' => 'verification_code_expires_at',
                'attempts' => 'verification_attempts',
                'locked' => 'verification_locked_until',
            ],
            self::RESET => [
                'hash' => 'reset_password_code',
                'expires' => 'reset_password_code_expires_at',
                'attempts' => 'reset_password_attempts',
                'locked' => 'reset_password_locked_until',
            ],
            default => throw new InvalidArgumentException('Unknown OTP purpose.'),
        };
    }

    private function assertPurpose(string $purpose): void
    {
        if (! in_array($purpose, [self::VERIFICATION, self::RESET], true)) {
            throw new InvalidArgumentException('Unknown OTP purpose.');
        }
    }

    private function sendCooldownKey(string $mobile): string
    {
        return 'otp-send-cooldown:'.$mobile;
    }

    private function sendHourlyKey(string $mobile): string
    {
        return 'otp-send-hourly:'.$mobile;
    }
}
