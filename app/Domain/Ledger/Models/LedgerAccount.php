<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Enums\AccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    protected $fillable = ['key', 'type', 'business_id', 'currency'];

    protected $attributes = ['balance' => 0, 'currency' => 'TZS'];

    protected function casts(): array
    {
        return ['type' => AccountType::class, 'balance' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LedgerLine::class);
    }
}
