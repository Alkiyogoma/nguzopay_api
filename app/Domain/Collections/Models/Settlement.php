<?php

namespace App\Domain\Collections\Models;

use App\Domain\Business\Models\Business;
use App\Domain\Payouts\Models\Payout;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One business's T+0 sweep for one day (D-016). */
class Settlement extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settlement_date' => 'date',
            'cutoff_at' => 'datetime',
            'paid_at' => 'datetime',
            'payments_count' => 'integer',
            'gross_amount' => 'integer',
            'provider_fees' => 'integer',
            'platform_fees' => 'integer',
            'net_amount' => 'integer',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class)->withTrashed();
    }

    public function payout(): HasOne
    {
        return $this->hasOne(Payout::class);
    }
}
