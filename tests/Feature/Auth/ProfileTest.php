<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_verify_a_phone_then_turn_on_two_factor(): void
    {
        $user = $this->signIn();

        $this->putJson('/api/v1/me/two-factor', ['enabled' => true, 'password' => 'password123'])
            ->assertUnprocessable()->assertJsonValidationErrors('enabled');

        $this->postJson('/api/v1/me/phone', ['phone' => '0765111222'])->assertOk();
        $this->postJson('/api/v1/me/phone/verify', ['phone' => '0765111222', 'code' => $this->lastOtp('255765111222')])
            ->assertOk()->assertJsonPath('data.phone', '255765111222')->assertJsonPath('data.phone_verified', true);

        $this->putJson('/api/v1/me/two-factor', ['enabled' => true, 'password' => 'wrong'])->assertUnprocessable();
        $this->putJson('/api/v1/me/two-factor', ['enabled' => true, 'password' => 'password123'])
            ->assertOk()->assertJsonPath('data.two_factor_enabled', true);
    }

    public function test_phone_already_used_by_someone_else(): void
    {
        User::factory()->withPhone('255765111222')->create();
        $this->signIn();

        $this->postJson('/api/v1/me/phone', ['phone' => '0765111222'])->assertUnprocessable();
    }

    public function test_change_password_signs_out_other_devices(): void
    {
        $user = User::factory()->create();
        $user->createToken('laptop');
        $token = $user->createToken('phone')->plainTextToken;

        $this->withToken($token)->putJson('/api/v1/me/password', [
            'current_password' => 'password123', 'password' => 'newpass12345', 'password_confirmation' => 'newpass12345',
        ])->assertOk();

        $this->assertSame(['phone'], $user->tokens()->pluck('name')->all());
    }

    public function test_sessions_list_and_revoke(): void
    {
        $user = User::factory()->create();
        $old = $user->createToken('old');
        $token = $user->createToken('current')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me/sessions')->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->deleteJson("/api/v1/me/sessions/{$old->accessToken->id}")->assertOk();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_changing_email_requires_reverification(): void
    {
        Notification::fake();
        $this->signIn();

        $this->patchJson('/api/v1/me', ['email' => 'new@example.com'])
            ->assertOk()->assertJsonPath('data.email', 'new@example.com')->assertJsonPath('data.email_verified', false);
    }
}
