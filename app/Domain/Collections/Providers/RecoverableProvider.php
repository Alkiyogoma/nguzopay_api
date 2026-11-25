<?php

namespace App\Domain\Collections\Providers;

use App\Domain\Collections\Models\PaymentAttempt;
use App\Domain\Collections\Models\PaymentIntent;

/**
 * A provider that can be asked about a payment, and whose initiation is
 * idempotent, so an ambiguous send can be replayed with the same key.
 * The reconciler uses this instead of guessing from age.
 */
interface RecoverableProvider
{
    /** Minutes the provider keeps a payment open; we never give up on it sooner. */
    public function maxPendingMinutes(): int;

    /** Re-sending the same attempt is safe (same idempotency key → same payment). */
    public function canReplayInitiation(): bool;

    /**
     * Ask the provider for the payment's current state.
     * Returns null while it is still pending, or when the answer cannot be trusted.
     */
    public function fetchStatus(PaymentAttempt $attempt, PaymentIntent $intent): ?ProviderNotification;

    /** Send the payer's phone prompt again. */
    public function resendPush(PaymentAttempt $attempt, PaymentIntent $intent): bool;
}
