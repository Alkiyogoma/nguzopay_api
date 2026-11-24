<?php

namespace App\Domain\Identity\Models;

use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Identity\Notifications\QueuedResetPassword;
use App\Domain\Identity\Notifications\QueuedVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'avatar_url',
    ];

    protected $attributes = [
        'two_factor_enabled' => false,
        'is_platform_admin' => false,
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
            'is_platform_admin' => 'boolean',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessMember::class);
    }

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'business_members')
            ->withPivot(['role', 'status', 'job_title'])
            ->withTimestamps();
    }

    public function membershipIn(Business $business): ?BusinessMember
    {
        return $this->memberships()->where('business_id', $business->getKey())->first();
    }

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone !== null && $this->phone_verified_at !== null;
    }

    /** How many ways the user can still sign in. Used to stop them locking themselves out. */
    public function signInMethodCount(): int
    {
        return ($this->hasPassword() && $this->email ? 1 : 0)
            + ($this->hasVerifiedPhone() ? 1 : 0)
            + $this->socialAccounts()->count();
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new QueuedResetPassword($token));
    }

    // MustVerifyEmail: users without an email (SMS or GitHub sign-up) have nothing to verify.
    public function hasVerifiedEmail(): bool
    {
        return $this->email === null || $this->email_verified_at !== null;
    }
}
