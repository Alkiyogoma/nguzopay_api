<?php

namespace App\Domain\Collections\Models;

use App\Domain\Collections\Enums\IntentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One try of an intent on one provider. Its id is the reference the provider echoes back. */
class PaymentAttempt extends Model
{
    use HasUlids;

    protected $fillable = ['provider', 'provider_reference', 'status', 'error_kind', 'message', 'request', 'response'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['status' => IntentStatus::class, 'request' => 'array', 'response' => 'array'];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }
}
