<?php

namespace App\Domain\Business\Models;

use App\Domain\Business\Enums\MemberStatus;
use App\Domain\Business\Enums\StaffRole;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessMember extends Model
{
    protected $fillable = ['user_id', 'role', 'status', 'job_title', 'invited_by'];

    protected function casts(): array
    {
        return ['role' => StaffRole::class, 'status' => MemberStatus::class];
    }

    protected $attributes = ['status' => 'active'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
