<?php

namespace App\Domain\Payouts\Models;

use App\Domain\Business\Models\Business;
use App\Domain\Collections\Enums\MobileOperator;
use App\Domain\Collections\Models\Settlement;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A merchant withdrawal of its available balance (D-027). */
class Payout extends Model
{
    use HasUlids;

    public const OPEN = ['requested', 'processing'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'operator' => MobileOperator::class,
            'processed_at' => 'datetime',
        ];
    }

    /** Money already promised away: requested or being paid. */
    public function scopeOpen(Builder $q): void
    {
        $q->whereIn('payouts.status', self::OPEN);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class)->withTrashed();
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function total(): int
    {
        return $this->amount + $this->fee;
    }

    /** "••••••0809" for phones and account numbers. */
    public function maskedAccount(): string
    {
        return str_repeat('•', 6).substr($this->recipient_account, -4);
    }
}
