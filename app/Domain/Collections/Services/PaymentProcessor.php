<?php

namespace App\Domain\Collections\Services;

use App\Domain\Collections\Enums\IntentStatus;
use App\Domain\Collections\Models\InboundEvent;
use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Models\PaymentAttempt;
use App\Domain\Collections\Models\PaymentIntent;
use App\Domain\Collections\Providers\ProviderNotification;
use App\Domain\Platform\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Turns a verified provider notification into state: a payment, its
 * allocations, customer credit and the ledger entry, all in one transaction.
 * Safe to run twice for the same event.
 */
class PaymentProcessor
{
    public function __construct(
        private readonly PaymentRecorder $recorder,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(InboundEvent $event, ProviderNotification $n): void
    {
        // A reversal after completion needs a person (refunds and chargebacks are not automated yet).
        if (str_ends_with((string) $n->eventType, 'reversed')) {
            $this->finish($event, 'needs_review', 'The provider reversed this payment.');

            return;
        }

        if ($n->outcome === null || $n->outcome === IntentStatus::Pending || $n->outcome === IntentStatus::Processing) {
            $this->finish($event, 'ignored');

            return;
        }

        if ($n->currency !== null && strtoupper($n->currency) !== 'TZS') {
            $this->finish($event, 'needs_review', "Unexpected currency {$n->currency}.");

            return;
        }

        $attempt = $this->findAttempt($event->provider, $n);
        if (! $attempt) {
            // Money with no request behind it goes to the suspense queue (P2); for now it is kept here.
            $this->finish($event, 'unmatched', 'No payment request matches this notification.');

            return;
        }

        DB::transaction(function () use ($event, $n, $attempt) {
            $intent = PaymentIntent::whereKey($attempt->payment_intent_id)->lockForUpdate()->firstOrFail();
            $attempt = PaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $event->payment_attempt_id = $attempt->id;

            if ($n->outcome === IntentStatus::Succeeded) {
                $this->succeed($event, $n, $intent, $attempt);

                return;
            }

            // Failure, expiry or cancellation. A recorded success always wins.
            if ($intent->status === IntentStatus::Succeeded || $attempt->status === IntentStatus::Succeeded) {
                $this->finish($event, 'ignored', 'Already paid.');

                return;
            }

            $attempt->forceFill(['status' => $n->outcome, 'message' => $n->failureReason ? mb_substr($n->failureReason, 0, 255) : null])->save();

            $isLatest = $intent->attempts()->reorder('id', 'desc')->value('id') === $attempt->id;
            if ($isLatest && $intent->status !== $n->outcome) {
                $intent->forceFill(['status' => $n->outcome, 'failure_reason' => mb_substr($n->failureReason ?? 'The payment did not go through.', 0, 255)])->save();
            }

            $this->finish($event, 'processed');
        });
    }

    private function succeed(InboundEvent $event, ProviderNotification $n, PaymentIntent $intent, PaymentAttempt $attempt): void
    {
        if (Payment::where('payment_intent_id', $intent->id)->exists()) {
            $this->finish($event, 'ignored', 'Payment already recorded.');

            return;
        }

        $intent->load('business', 'invoice', 'customer');
        $amount = $n->amount ?? $intent->amount;

        $payment = $this->recorder->record($intent->business, $intent->customer, [
            'payment_intent_id' => $intent->id,
            'payment_attempt_id' => $attempt->id,
            'provider' => $attempt->provider,
            'provider_reference' => $n->providerReference ?? $attempt->provider_reference ?? $attempt->id,
            'channel' => $intent->channel,
            'operator' => $n->operator ?? $intent->operator,
            'payer_phone' => $n->payerPhone ?? $intent->payer_phone,
            'amount' => $amount,
        ], $n->providerFee, $intent->invoice);

        if ($amount !== $intent->amount) {
            $this->audit->record('payment.amount_mismatch', $payment, ['requested' => $intent->amount, 'received' => $amount], business: $intent->business);
        }

        $attempt->forceFill(['status' => IntentStatus::Succeeded, 'provider_reference' => $attempt->provider_reference ?? $n->providerReference])->save();
        $intent->forceFill(['status' => IntentStatus::Succeeded, 'succeeded_at' => now(), 'failure_reason' => null])->save();

        $this->finish($event, 'processed');
    }

    private function findAttempt(string $provider, ProviderNotification $n): ?PaymentAttempt
    {
        if ($n->attemptId) {
            $attempt = PaymentAttempt::where('provider', $provider)->find($n->attemptId);

            // Our attempt id in the callback is only a hint: once the provider has given the
            // attempt its own reference, a callback carrying a different reference is not ours.
            if ($attempt && $n->referenceMustMatch && $attempt->provider_reference && $n->providerReference
                && $attempt->provider_reference !== $n->providerReference) {
                return null;
            }
            if ($attempt) {
                return $attempt;
            }
        }

        return $n->providerReference
            ? PaymentAttempt::where('provider', $provider)->where('provider_reference', $n->providerReference)->first()
            : null;
    }

    private function finish(InboundEvent $event, string $status, ?string $error = null): void
    {
        $event->forceFill(['status' => $status, 'error' => $error, 'processed_at' => now()])->save();
    }
}
