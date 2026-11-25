<?php

namespace App\Domain\Collections\Models;

use App\Domain\Billing\Models\ControlNumber;
use App\Domain\Billing\Models\Customer;
use App\Domain\Collections\Enums\MobileOperator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money that has actually arrived. Immutable once written, apart from settlement linkage. */
class Payment extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'operator' => MobileOperator::class,
            'amount' => 'integer',
            'provider_fee' => 'integer',
            'provider_fee_estimated' => 'boolean',
            'platform_fee' => 'integer',
            'net_amount' => 'integer',
            'allocated_amount' => 'integer',
            'credited_amount' => 'integer',
            'paid_at' => 'datetime',
            'details' => 'array',
        ];
    }

    /** Provider code for payments a merchant records by hand (cash, deposit, cheque…). */
    public const MANUAL = 'manual';

    public const METHODS = [
        'cash' => 'Cash',
        'bank_deposit' => 'Bank deposit / transfer',
        'cheque' => 'Cheque',
        'mobile_money' => 'Mobile money (outside NguzoPay)',
        'card' => 'Card',
        'other' => 'Other',
    ];

    public function isManual(): bool
    {
        return $this->provider === self::MANUAL;
    }

    /** Payments that count in totals (voided manual entries do not). */
    public function scopeCounted(Builder $q): void
    {
        $q->where('payments.status', 'succeeded');
    }

    /** Money NguzoPay actually holds for the merchant: swept at T+0. Manual payments never are. */
    public function scopeSettleable(Builder $q): void
    {
        $q->where('payments.status', 'succeeded')->where('payments.provider', '!=', self::MANUAL);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    public function controlNumber(): BelongsTo
    {
        return $this->belongsTo(ControlNumber::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
