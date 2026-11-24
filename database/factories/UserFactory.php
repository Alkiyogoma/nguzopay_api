<?php

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password123'),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function withPhone(?string $phone = null): static
    {
        return $this->state(fn () => [
            'phone' => $phone ?? '2557'.fake()->unique()->numerify('########'),
            'phone_verified_at' => now(),
        ]);
    }

    /** Verified phone + SMS two-step on. */
    public function withTwoFactor(?string $phone = null): static
    {
        return $this->withPhone($phone)->state(fn () => ['two_factor_enabled' => true]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['is_platform_admin' => true]);
    }
}
