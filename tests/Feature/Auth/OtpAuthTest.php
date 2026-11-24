<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\Models\OtpCode;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtpAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_sms_sign_in_creates_the_account(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0712 345 678'])
            ->assertOk()->assertJsonPath('phone_hint', '+255 7•• ••• 678');

        $code = $this->lastOtp('255712345678');
        $this->assertNotNull($code);
        $this->assertNotSame($code, OtpCode::first()->code_hash, 'only a hash is stored');

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '+255712345678', 'code' => $code, 'name' => 'Baraka'])
            ->assertCreated()
            ->assertJsonPath('user.phone', '255712345678')
            ->assertJsonPath('user.phone_verified', true);

        $this->assertSame(1, User::count());
    }

    public function test_existing_user_signs_in_and_code_is_single_use(): void
    {
        User::factory()->withPhone('255712345678')->create();

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0712345678'])->assertOk();
        $code = $this->lastOtp('255712345678');

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '0712345678', 'code' => $code])->assertOk();
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '0712345678', 'code' => $code])->assertUnprocessable();
        $this->assertSame(1, User::count());
    }

    public function test_wrong_codes_lock_the_code_after_max_attempts(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0712345678']);
        $code = $this->lastOtp('255712345678');
        $wrong = $code === '111111' ? '222222' : '111111';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/otp/verify', ['phone' => '0712345678', 'code' => $wrong])->assertUnprocessable();
        }

        // The sign-in rate limit (5/min) also kicks in; wait it out, the code stays locked.
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '0712345678', 'code' => $code])->assertTooManyRequests();
        $this->travel(61)->seconds();

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '0712345678', 'code' => $code])
            ->assertUnprocessable()->assertJsonFragment(['This code is invalid or has expired. Request a new one.']);
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0712345678']);
        $code = $this->lastOtp('255712345678');

        $this->travel(6)->minutes();

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '0712345678', 'code' => $code])->assertUnprocessable();
    }

    public function test_invalid_number_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0222123456'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_two_factor_users_cannot_use_sms_as_their_only_factor(): void
    {
        User::factory()->withPhone('255712345678')->create(['two_factor_enabled' => true]);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0712345678'])->assertOk();
        $this->assertNull($this->lastOtp('255712345678'), 'no code is sent');

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '0712345678', 'code' => '123456'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_otp_requests_are_rate_limited_per_number(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/otp/request', ['phone' => '0712345678'])->assertOk();
        }

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0712345678'])->assertTooManyRequests();
    }
}
