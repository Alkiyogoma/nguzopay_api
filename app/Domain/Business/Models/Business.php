<?php

namespace App\Domain\Business\Models;

use App\Domain\Business\Enums\BusinessStatus;
use App\Domain\Business\Enums\BusinessType;
use App\Domain\Business\Enums\MemberStatus;
use App\Domain\Business\Enums\RegistrationAuthority;
use App\Domain\Business\Enums\StaffRole;
use App\Domain\Identity\Models\User;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'type', 'legal_name', 'trading_name', 'registration_authority', 'registration_number',
        'tin', 'vrn', 'email', 'phone', 'website', 'region', 'district',
        'physical_address', 'postal_address',
    ];

    protected function casts(): array
    {
        return [
            'type' => BusinessType::class,
            'registration_authority' => RegistrationAuthority::class,
            'status' => BusinessStatus::class,
            'submitted_at' => 'datetime',
            'recommended_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    protected $attributes = ['status' => 'draft'];

    protected static function newFactory(): BusinessFactory
    {
        return BusinessFactory::new();
    }

    public function displayName(): string
    {
        return $this->trading_name ?: $this->legal_name;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(BusinessMember::class);
    }

    public function owner(): HasOne
    {
        return $this->hasOne(BusinessMember::class)->where('role', StaffRole::Owner);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(StaffInvitation::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BusinessDocument::class);
    }

    public function senderIds(): HasMany
    {
        return $this->hasMany(SmsSenderId::class);
    }

    public function recommendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recommended_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The user's active role here, or null if they are not active staff. */
    public function roleOf(User $user): ?StaffRole
    {
        $member = $this->members()
            ->where('user_id', $user->getKey())
            ->where('status', MemberStatus::Active)
            ->first();

        return $member?->role;
    }
}
