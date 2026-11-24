<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\QueuedResetPassword;
use App\Domain\Identity\Notifications\QueuedVerifyEmail;
use App\Domain\Platform\Models\AuditLog;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_returns_a_token_and_sends_verification_email(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Asha Mushi',
            'email' => 'Asha@Example.com',
            'password' => 'secret12345',
            'password_confirmation' => 'secret12345',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'asha@example.com')
            ->assertJsonPath('user.email_verified', false)
            ->assertJsonStructure(['token', 'expires_at']);

        $user = User::firstWhere('email', 'asha@example.com');
        Notification::assertSentTo($user, QueuedVerifyEmail::class);
        $this->assertTrue(AuditLog::where('action', 'auth.registered')->exists());

        $this->withToken($response->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.name', 'Asha Mushi');
    }

    public function test_weak_or_duplicate_registration_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'X', 'email' => 'taken@example.com', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_and_logout(): void
    {
        $user = User::factory()->create(['email' => 'juma@example.com']);

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'JUMA@example.com', 'password' => 'password123'])
            ->assertOk()->json('token');

        $this->assertNotNull($user->fresh()->last_login_at);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_wrong_password_fails_and_is_audited(): void
    {
        User::factory()->create(['email' => 'juma@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'juma@example.com', 'password' => 'nope'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertTrue(AuditLog::where('action', 'auth.login_failed')->exists());
    }

    public function test_guests_get_401_not_a_redirect(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->get('/api/v1/me')->assertUnauthorized();
    }

    public function test_email_verification_link(): void
    {
        $user = User::factory()->unverified()->create();
        $url = (new VerifyEmail)->toMail($user)->actionUrl;

        $this->get($url)->assertRedirect(config('nguzopay.frontend_url').'/verify-email?status=verified');
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
    }

    public function test_password_reset_flow_revokes_sessions(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'neema@example.com']);
        $user->createToken('old');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'neema@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, QueuedResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => 'neema@example.com',
            'password' => 'brandnew123', 'password_confirmation' => 'brandnew123',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['email' => 'neema@example.com', 'password' => 'brandnew123'])->assertOk();
    }

    public function test_two_factor_login_requires_the_sms_code(): void
    {
        $user = User::factory()->withPhone('255712000111')->create(['email' => 'fin@example.com', 'two_factor_enabled' => true]);

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => 'fin@example.com', 'password' => 'password123'])
            ->assertStatus(202)
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonMissingPath('token')
            ->json('challenge');

        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertUnprocessable();

        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $this->lastOtp('255712000111')])
            ->assertOk()->assertJsonStructure(['token']);

        // A challenge cannot be reused.
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => '123456'])
            ->assertUnprocessable()->assertJsonValidationErrors('challenge');

        $this->assertSame(1, $user->tokens()->count());
    }
}
