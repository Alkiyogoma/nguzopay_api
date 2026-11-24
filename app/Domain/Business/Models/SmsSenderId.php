<?php

namespace App\Domain\Business\Models;

use App\Domain\Business\Enums\SenderIdStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsSenderId extends Model
{
    protected $fillable = ['sender_id', 'status', 'rejection_reason', 'approved_at'];

    protected function casts(): array
    {
        return ['status' => SenderIdStatus::class, 'approved_at' => 'datetime'];
    }

    protected $attributes = ['status' => 'requested'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
