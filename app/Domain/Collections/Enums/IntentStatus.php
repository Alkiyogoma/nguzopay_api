<?php

namespace App\Domain\Collections\Enums;

/**
 * pending (created) ─send→ processing (push sent, waiting for the payer)
 *   ─webhook→ succeeded | failed;  ─timer→ expired;  ─merchant→ cancelled.
 * A success that arrives after expiry still wins: the money has moved.
 */
enum IntentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Processing], true);
    }
}
