<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class Installment extends Model
{
    public $timestamps = false;

    protected $fillable = ['sequence', 'due_date', 'amount'];

    protected $attributes = ['amount_paid' => 0];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'amount' => 'integer', 'amount_paid' => 'integer', 'sequence' => 'integer'];
    }

    public function balance(): int
    {
        return max(0, $this->amount - $this->amount_paid);
    }

    public function status(): string
    {
        return match (true) {
            $this->amount_paid >= $this->amount => 'paid',
            $this->amount_paid > 0 => 'partially_paid',
            default => 'open',
        };
    }
}
