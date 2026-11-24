<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\SocialProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    protected $fillable = ['provider', 'provider_user_id', 'email', 'avatar_url'];

    protected function casts(): array
    {
        return ['provider' => SocialProvider::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
