<?php

namespace App\Domain\Billing\Enums;

/** open ─pay→ partially_paid ─pay→ paid. open|partially_paid ─void→ void (only with nothing paid). */
enum InvoiceStatus: string
{
    case Open = 'open';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    public function acceptsPayment(): bool
    {
        return in_array($this, [self::Open, self::PartiallyPaid], true);
    }
}
