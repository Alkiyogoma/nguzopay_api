<?php

namespace App\Domain\Collections\Models;

use App\Domain\Billing\Models\Customer;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Business\Models\Business;
use App\Domain\Collections\Enums\IntentStatus;
use App\Domain\Collections\Enums\MobileOperator;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentIntent extends Model
{
    use HasUlids;

    protected $fillable = ['amount', 'operator', 'payer_phone', 'description', 'idempotency_key', 'metadata', 'expires_at'];

    protected $attributes = ['status' => 'pending', 'currency' => 'TZS', 'channel' => 'mobile_money'];

    protected function casts(): array
    {
        return [
            'status' => IntentStatus::class,
            'operator' => MobileOperator::class,
            'amount' => 'integer',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'succeeded_at' => 'datetime',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->orderBy('created_at');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}
