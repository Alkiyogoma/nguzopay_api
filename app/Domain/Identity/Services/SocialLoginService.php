<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\SocialProvider;
use App\Domain\Identity\Models\SocialAccount;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;

/**
 * Google and GitHub sign-in for an API + separate SPA:
 *  1. redirectUrl(): we create our own `state` (cached, single use) and send the browser to the provider.
 *  2. The provider returns to our callback; resolve() checks the state and finds or creates the user.
 *  3. We redirect to the frontend with a short-lived one-time code (never the token itself in a URL).
 *  4. The frontend swaps the code for a token with exchange().
 */
class SocialLoginService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function redirectUrl(SocialProvider $provider, string $intent = 'login', ?User $user = null): string
    {
        $state = Str::random(40);

        Cache::put('auth:oauth-state:'.$state, [
            'provider' => $provider->value,
            'intent' => $intent,
            'user_id' => $user?->getKey(),
        ], now()->addMinutes((int) config('nguzopay.auth.oauth_state_ttl_minutes')));

        return $this->driver($provider)->with(['state' => $state])->redirect()->getTargetUrl();
    }

    /**
     * @return array{user: User, intent: string}
     *
     * @throws SocialLoginFailed
     */
    public function resolve(SocialProvider $provider, ?string $state): array
    {
        $pending = $state ? Cache::pull('auth:oauth-state:'.$state) : null;

        if (! $pending || $pending['provider'] !== $provider->value) {
            throw new SocialLoginFailed('oauth_state_invalid');
        }

        try {
            $social = $this->driver($provider)->user();
        } catch (\Throwable $e) {
            report($e);
            throw new SocialLoginFailed('oauth_provider_error');
        }

        if ($pending['intent'] === 'link') {
            $user = User::find($pending['user_id']) ?? throw new SocialLoginFailed('oauth_state_invalid');
            $this->link($user, $provider, $social);
            $this->audit->record('auth.social_linked', $user, ['provider' => $provider->value], actor: $user);

            return ['user' => $user, 'intent' => 'link'];
        }

        return ['user' => $this->findOrCreate($provider, $social), 'intent' => 'login'];
    }

    public function createExchangeCode(User $user): string
    {
        $code = Str::random(48);
        Cache::put('auth:oauth-code:'.hash('sha256', $code), $user->getKey(),
            now()->addSeconds((int) config('nguzopay.auth.oauth_exchange_ttl_seconds')));

        return $code;
    }

    public function exchange(string $code): ?User
    {
        $userId = Cache::pull('auth:oauth-code:'.hash('sha256', $code));

        return $userId ? User::find($userId) : null;
    }

    private function findOrCreate(SocialProvider $provider, SocialUser $social): User
    {
        $account = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', (string) $social->getId())
            ->first();

        if ($account) {
            $account->update(['email' => $social->getEmail(), 'avatar_url' => $social->getAvatar()]);

            return $account->user;
        }

        $email = $social->getEmail() ? Str::lower($social->getEmail()) : null;
        $emailVerified = $email && $this->providerVerifiedEmail($provider, $social);

        return DB::transaction(function () use ($provider, $social, $email, $emailVerified) {
            $user = $email ? User::where('email', $email)->first() : null;

            if ($user && ! $emailVerified) {
                throw new SocialLoginFailed('oauth_email_unverified');
            }

            if ($user) {
                // The provider proved ownership of this email. If the existing account never
                // verified it, whoever set its password may not own the address: drop the
                // password and sessions so only the real owner keeps access.
                if (! $user->email_verified_at) {
                    $user->forceFill(['password' => null, 'email_verified_at' => now()])->save();
                    $user->tokens()->delete();
                }
            } else {
                $user = new User([
                    'name' => $social->getName() ?: $social->getNickname(),
                    'email' => $emailVerified ? $email : null,
                    'avatar_url' => $social->getAvatar(),
                ]);
                $user->email_verified_at = $emailVerified ? now() : null;
                $user->save();
                $this->audit->record('auth.registered', $user, ['method' => $provider->value], actor: $user);
            }

            $this->link($user, $provider, $social);

            return $user;
        });
    }

    private function link(User $user, SocialProvider $provider, SocialUser $social): void
    {
        $existing = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', (string) $social->getId())
            ->first();

        if ($existing && $existing->user_id !== $user->getKey()) {
            throw new SocialLoginFailed('oauth_already_linked');
        }

        $user->socialAccounts()->updateOrCreate(
            ['provider' => $provider],
            [
                'provider_user_id' => (string) $social->getId(),
                'email' => $social->getEmail(),
                'avatar_url' => $social->getAvatar(),
            ],
        );

        if (! $user->avatar_url && $social->getAvatar()) {
            $user->forceFill(['avatar_url' => $social->getAvatar()])->save();
        }
    }

    /** Google says so explicitly; Socialite's GitHub driver only returns a primary, verified email. */
    private function providerVerifiedEmail(SocialProvider $provider, SocialUser $social): bool
    {
        return match ($provider) {
            SocialProvider::Google => (bool) ($social->user['email_verified'] ?? $social->user['verified_email'] ?? false),
            SocialProvider::GitHub => true,
        };
    }

    private function driver(SocialProvider $provider)
    {
        return Socialite::driver($provider->value)->stateless();
    }
}
