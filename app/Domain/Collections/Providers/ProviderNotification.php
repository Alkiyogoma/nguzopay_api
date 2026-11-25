<?php

namespace App\Domain\Collections\Providers;

use App\Domain\Collections\Enums\IntentStatus;
use App\Domain\Collections\Enums\MobileOperator;

/** A provider webhook after signature checks, in NguzoPay's terms. */
final class ProviderNotification
{
    public function __construct(
        public readonly string $eventKey,              // de-duplication key, unique per provider
        public readonly ?string $eventType,
        public readonly ?string $attemptId,            // our reference echoed back
        public readonly ?string $providerReference,
        public readonly ?IntentStatus $outcome,        // null = nothing to do (e.g. a payout event)
        public readonly ?int $amount = null,
        public readonly ?int $providerFee = null,
        public readonly ?MobileOperator $operator = null,
        public readonly ?string $payerPhone = null,
        public readonly ?string $failureReason = null,
        public readonly array $payload = [],
        public readonly ?string $currency = null,
        // True when the provider's callback reference must equal the one it returned at
        // initiation (Snippe). Then our attempt id in the callback is only a lookup hint.
        public readonly bool $referenceMustMatch = false,
    ) {}
}
