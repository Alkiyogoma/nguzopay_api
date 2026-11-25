<?php

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Business\Models\Business;
use App\Domain\Collections\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    use HasUlids;

    protected $fillable = ['external_reference', 'description', 'allow_partial', 'min_amount', 'due_date'];

    protected $attributes = ['status' => 'open', 'amount_paid' => 0, 'currency' => 'TZS', 'allow_partial' => true, 'version' => 1];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'amount_due' => 'integer',
            'amount_paid' => 'integer',
            'min_amount' => 'integer',
            'allow_partial' => 'boolean',
            'due_date' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('sequence');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function controlNumber(): HasOne
    {
        return $this->hasOne(ControlNumber::class);
    }

    public function balance(): int
    {
        return max(0, $this->amount_due - $this->amount_paid);
    }

    public function isOverdue(): bool
    {
        return $this->status->acceptsPayment() && $this->due_date && $this->due_date->isPast() && ! $this->due_date->isToday();
    }

    /** Smallest amount a payer may pay now: the full balance when partial payments are off. */
    public function minimumPayment(): int
    {
        $balance = $this->balance();

        if (! $this->allow_partial) {
            return $balance;
        }

        return min($balance, max(1, (int) $this->min_amount));
    }
}
