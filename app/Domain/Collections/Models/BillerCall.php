<?php

namespace App\Domain\Collections\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One bank call to a biller endpoint and our answer. Append-only. */
class BillerCall extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'response' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Biller calls are append-only.'));
        static::deleting(fn () => throw new LogicException('Biller calls are append-only.'));
    }
}
