<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['description', 'quantity', 'unit_amount', 'amount', 'position'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_amount' => 'integer', 'amount' => 'integer'];
    }
}
