<?php

namespace App\Providers;

use App\Domain\Business\Models\Business;
use App\Domain\Business\Policies\BusinessPolicy;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Sms\DarSmsSender;
use App\Domain\Notifications\Sms\LogSmsSender;
use App\Domain\Notifications\Sms\SmsSender;
use App\Domain\Platform\Vault\EnvKeyProvider;
use App\Domain\Platform\Vault\KeyProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsSender::class, function () {
            return match (config('nguzopay.sms.driver')) {
                'darsms' => new DarSmsSender(
                    config('nguzopay.sms.darsms.base_url'),
                    config('nguzopay.sms.darsms.api_key'),
                    (int) config('nguzopay.sms.darsms.timeout'),
                    (string) config('nguzopay.sms.darsms.auth'),
                ),
                default => new LogSmsSender,
            };
        });

        $this->app->singleton(KeyProvider::class, fn () => new EnvKeyProvider(
            config('nguzopay.vault.active_key'),
            config('nguzopay.vault.keys'),
        ));
    }

    public function boot(): void
    {
        // Catch N+1 queries and silently dropped attributes outside production.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Gate::policy(Business::class, BusinessPolicy::class);

        ResetPassword::createUrlUsing(fn (User $user, string $token) => config('nguzopay.frontend_url')
            .'/reset-password?token='.$token.'&email='.urlencode($user->email));

        VerifyEmail::createUrlUsing(fn (User $user) => URL::temporarySignedRoute(
            'v1.auth.verification.verify',
            now()->addHours(24),
            ['id' => $user->getKey(), 'hash' => sha1($user->email)],
        ));

        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('id:'.strtolower((string) ($request->input('email') ?? $request->input('phone') ?? $request->input('challenge')))),
        ]);

        // Outgoing SMS jobs stay below DarSMS's 180 requests/min per API key.
        RateLimiter::for('darsms', fn () => Limit::perMinute(150));

        // SMS costs money and can be abused to spam numbers: 3 per 10 min per number, 10/hour per IP.
        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinutes(10, 3)->by('phone:'.preg_replace('/\D/', '', (string) $request->input('phone'))),
            Limit::perHour(10)->by('ip:'.$request->ip()),
        ]);
    }
}
