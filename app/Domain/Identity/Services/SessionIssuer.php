<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\PhoneNumber;
use App\Domain\Platform\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The last step of every sign-in method. Either issues a portal token, or,
 * when the user has 2FA on, sends an SMS code and returns a challenge.
 */
class SessionIssuer
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{two_factor_required: true, challenge: string, phone_hint: string}
     *                                                                                 |array{token: string, expires_at: string, user: User}
     */
    public function begin(User $user, string $method, ?string $deviceName = null, ?string $ip = null): array
    {
        if ($user->two_factor_enabled && $user->hasVerifiedPhone()) {
            $challenge = Str::random(48);

            Cache::put(
                $this->challengeKey($challenge),
                ['user_id' => $user->getKey(), 'method' => $method, 'device' => $deviceName],
                now()->addMinutes((int) config('nguzopay.auth.two_factor_challenge_ttl_minutes')),
            );

            $this->otp->send($user->phone, OtpPurpose::TwoFactor, $user, $ip);
            $this->audit->record('auth.two_factor_challenged', $user, ['method' => $method], actor: $user);

            return [
                'two_factor_required' => true,
                'challenge' => $challenge,
                'phone_hint' => PhoneNumber::mask($user->phone),
            ];
        }

        return $this->issue($user, $method, $deviceName);
    }

    /** @return array{token: string, expires_at: string, user: User} */
    public function completeTwoFactor(string $challenge, string $code): array
    {
        $key = $this->challengeKey($challenge);
        $pending = Cache::get($key);
        $user = $pending ? User::find($pending['user_id']) : null;

        if (! $user || ! $user->hasVerifiedPhone()) {
            throw ValidationException::withMessages(['challenge' => 'This sign-in attempt has expired. Sign in again.']);
        }

        $this->otp->verify($user->phone, OtpPurpose::TwoFactor, $code);
        Cache::forget($key);

        return $this->issue($user, $pending['method'].'+sms', $pending['device']);
    }

    /** @return array{token: string, expires_at: string, user: User} */
    public function issue(User $user, string $method, ?string $deviceName = null): array
    {
        $expiresAt = now()->addMinutes((int) config('nguzopay.auth.token_ttl_minutes'));
        $token = $user->createToken(Str::limit($deviceName ?: 'portal', 100, ''), ['*'], $expiresAt);

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.login', $user, ['method' => $method], actor: $user);

        return [
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => $user,
        ];
    }

    private function challengeKey(string $challenge): string
    {
        return 'auth:2fa:'.hash('sha256', $challenge);
    }
}
