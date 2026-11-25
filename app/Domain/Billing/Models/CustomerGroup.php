<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/** A set of customers tracked together, e.g. a school class. */
class CustomerGroup extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'description'];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'group_id');
    }

    public function invoices(): HasManyThrough
    {
        return $this->hasManyThrough(Invoice::class, Customer::class, 'group_id', 'customer_id');
    }
}
