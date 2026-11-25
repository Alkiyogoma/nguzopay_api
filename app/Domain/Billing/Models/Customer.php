<?php

namespace App\Domain\Billing\Models;

use App\Domain\Business\Models\Business;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A payer of one business: a parent/student, a member, a borrower. */
class Customer extends Model
{
    use HasUlids;

    protected $fillable = ['reference', 'name', 'phone', 'email', 'metadata', 'group_id'];

    protected $attributes = ['credit_balance' => 0];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'credit_balance' => 'integer'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'group_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Permanent account control number (plan §2.5). */
    public function accountNumber(): HasOne
    {
        return $this->hasOne(ControlNumber::class)->where('type', 'customer');
    }

    /** First and last name for providers that want them split (Snippe). */
    public function nameParts(): array
    {
        $parts = preg_split('/\s+/', trim($this->name), 2) ?: [];

        return [$parts[0] ?? $this->name, $parts[1] ?? $parts[0] ?? $this->name];
    }
}
