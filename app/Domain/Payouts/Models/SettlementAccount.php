<?php

namespace App\Domain\Payouts\Models;

use App\Domain\Collections\Enums\MobileOperator;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Where the T+0 sweep sends a business's money (D-029). One per business. */
class SettlementAccount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['operator' => MobileOperator::class];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function masked(): string
    {
        return str_repeat('•', 6).substr($this->account_number, -4);
    }
}
