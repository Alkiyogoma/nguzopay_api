<?php

namespace App\Domain\Collections\Providers;

use App\Domain\Collections\Models\PaymentAttempt;
use App\Domain\Collections\Models\PaymentIntent;
use Illuminate\Http\Request;

/**
 * A mobile-money collection provider (DEVELOPMENT_PLAN §2.2, collections half).
 * Bank billers (NMB, CRDB) get their own contract in P2.
 */
interface CollectionProvider
{
    public function code(): string;

    /** @return array{min: int, max: int} amount limits in TZS */
    public function limits(): array;

    /** True when the provider needs the payer's network named (it cannot detect it). */
    public function requiresOperator(): bool;

    /**
     * Send the push. Never throws for provider errors: a timeout or 5xx is
     * ambiguous (stay processing, wait for the webhook or expiry), a 4xx is definite.
     */
    public function initiate(PaymentAttempt $attempt, PaymentIntent $intent): InitiationResult;

    /**
     * Verify and normalise a webhook from the raw request.
     *
     * @throws InvalidNotification when the signature or timestamp is wrong
     */
    public function parseNotification(Request $request): ProviderNotification;
}
