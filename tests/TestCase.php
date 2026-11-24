<?php

namespace Tests;

use App\Domain\Business\Enums\StaffRole;
use App\Domain\Business\Models\Business;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Sms\SmsSender;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function signIn(?User $user = null): User
    {
        $user ??= User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /** The last OTP texted to a number (log SMS driver). */
    protected function lastOtp(string $phone): ?string
    {
        $message = app(SmsSender::class)->lastTo($phone);

        return $message && preg_match('/\b(\d{6})\b/', $message, $m) ? $m[1] : null;
    }

    protected function businessWith(User $user, StaffRole $role = StaffRole::Owner, array $attributes = []): Business
    {
        $business = Business::factory()->create($attributes);
        $business->members()->create(['user_id' => $user->id, 'role' => $role]);

        return $business;
    }
}
