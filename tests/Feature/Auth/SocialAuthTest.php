<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google' => ['client_id' => 'g-id', 'client_secret' => 'g-secret', 'redirect' => 'http://localhost/api/v1/auth/oauth/google/callback'],
            'services.github' => ['client_id' => 'gh-id', 'client_secret' => 'gh-secret', 'redirect' => 'http://localhost/api/v1/auth/oauth/github/callback'],
        ]);
    }

    /** Starts the real redirect (so our state is cached) and returns that state. */
    private function startLogin(string $provider): string
    {
        $location = $this->get("/api/v1/auth/oauth/{$provider}/redirect")->assertRedirect()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['state']);

        return $query['state'];
    }

    private function fakeProviderUser(string $provider, array $attributes, array $raw = []): void
    {
        $social = (new SocialiteUser)->setRaw($raw)->map(array_merge([
            'id' => '123', 'nickname' => 'asha', 'name' => 'Asha M', 'email' => null, 'avatar' => 'https://img/a.png',
        ], $attributes));

        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($social);
        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
    }

    private function exchangeFrom($callbackResponse): TestResponse
    {
        $location = $callbackResponse->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(config('nguzopay.frontend_url').'/auth/callback', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        return $this->postJson('/api/v1/auth/oauth/exchange', ['code' => $query['code']]);
    }

    public function test_redirect_goes_to_google_with_our_state(): void
    {
        $location = $this->get('/api/v1/auth/oauth/google/redirect')->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/', $location);
        $this->assertStringContainsString('state=', $location);
    }

    public function test_google_sign_in_creates_a_verified_user(): void
    {
        $state = $this->startLogin('google');
        $this->fakeProviderUser('google', ['email' => 'asha@gmail.com'], ['email_verified' => true]);

        $response = $this->exchangeFrom($this->get("/api/v1/auth/oauth/google/callback?state={$state}&code=x"));

        $response->assertOk()->assertJsonPath('user.email', 'asha@gmail.com')->assertJsonPath('user.email_verified', true);
        $this->assertSame('google', User::first()->socialAccounts()->first()->provider->value);
    }

    public function test_exchange_code_is_single_use(): void
    {
        $state = $this->startLogin('github');
        $this->fakeProviderUser('github', ['email' => 'dev@example.com']);

        $location = $this->get("/api/v1/auth/oauth/github/callback?state={$state}")->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->postJson('/api/v1/auth/oauth/exchange', ['code' => $query['code']])->assertOk();
        $this->postJson('/api/v1/auth/oauth/exchange', ['code' => $query['code']])->assertUnprocessable();
    }

    public function test_unknown_or_reused_state_is_rejected(): void
    {
        $this->get('/api/v1/auth/oauth/google/callback?state=forged')
            ->assertRedirect(config('nguzopay.frontend_url').'/login?error=oauth_state_invalid');

        $state = $this->startLogin('google');
        $this->fakeProviderUser('google', ['email' => 'a@gmail.com'], ['email_verified' => true]);
        $this->get("/api/v1/auth/oauth/google/callback?state={$state}");
        $this->get("/api/v1/auth/oauth/google/callback?state={$state}")
            ->assertRedirect(config('nguzopay.frontend_url').'/login?error=oauth_state_invalid');
    }

    public function test_github_links_to_existing_account_with_same_verified_email(): void
    {
        $existing = User::factory()->create(['email' => 'dev@example.com']);

        $state = $this->startLogin('github');
        $this->fakeProviderUser('github', ['email' => 'dev@example.com']);
        $this->exchangeFrom($this->get("/api/v1/auth/oauth/github/callback?state={$state}"))
            ->assertOk()->assertJsonPath('user.id', $existing->id);

        $this->assertSame(1, User::count());
        $this->assertNotNull($existing->fresh()->password, 'verified owner keeps their password');
    }

    public function test_linking_to_an_unverified_account_removes_the_squatters_password(): void
    {
        $squatter = User::factory()->unverified()->create(['email' => 'victim@gmail.com']);
        $squatter->createToken('attacker');

        $state = $this->startLogin('google');
        $this->fakeProviderUser('google', ['email' => 'victim@gmail.com'], ['email_verified' => true]);
        $this->exchangeFrom($this->get("/api/v1/auth/oauth/google/callback?state={$state}"))->assertOk();

        $squatter->refresh();
        $this->assertNull($squatter->password);
        $this->assertNotNull($squatter->email_verified_at);
        $this->assertSame(1, $squatter->tokens()->count(), 'only the new session remains');
    }

    public function test_unverified_google_email_cannot_take_over_an_account(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);

        $state = $this->startLogin('google');
        $this->fakeProviderUser('google', ['email' => 'owner@example.com'], ['email_verified' => false]);

        $this->get("/api/v1/auth/oauth/google/callback?state={$state}")
            ->assertRedirect(config('nguzopay.frontend_url').'/login?error=oauth_email_unverified');
    }

    public function test_signed_in_user_links_github(): void
    {
        $user = $this->signIn(User::factory()->create());

        $url = $this->postJson('/api/v1/auth/oauth/github/link')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->fakeProviderUser('github', ['id' => '999', 'email' => 'other@example.com']);
        $this->get("/api/v1/auth/oauth/github/callback?state={$query['state']}")
            ->assertRedirect(config('nguzopay.frontend_url').'/settings/profile?linked=github');

        $this->assertSame('999', $user->socialAccounts()->first()->provider_user_id);
    }

    public function test_cannot_unlink_the_only_sign_in_method(): void
    {
        $user = User::factory()->create(['password' => null]);
        $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => '1']);
        $this->signIn($user);

        $this->deleteJson('/api/v1/me/social/google')->assertUnprocessable();
    }
}
