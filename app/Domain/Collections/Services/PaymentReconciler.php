<?php

namespace App\Domain\Collections\Services;

use App\Domain\Collections\Enums\IntentStatus;
use App\Domain\Collections\Models\InboundEvent;
use App\Domain\Collections\Models\PaymentAttempt;
use App\Domain\Collections\Providers\InitiationResult;
use App\Domain\Collections\Providers\ProviderRegistry;
use App\Domain\Collections\Providers\RecoverableProvider;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Settles payments the webhook did not (yet) settle, by asking the provider
 * rather than guessing from age:
 *  1. an ambiguous send with no provider reference is replayed with the same
 *     idempotency key, which returns the original payment, never a second one;
 *  2. a waiting payment's status is read and, when final, processed exactly
 *     like a webhook (same de-duplication, same transaction).
 */
class PaymentReconciler
{
    /** Leave fresh payments to their webhook for this long. */
    private const MIN_AGE_SECONDS = 90;

    public function __construct(private readonly ProviderRegistry $providers, private readonly PaymentProcessor $processor) {}

    /** @return array{replayed: int, settled: int} */
    public function run(): array
    {
        $replayed = $settled = 0;

        PaymentAttempt::with('intent.customer', 'intent.business')
            ->where('status', IntentStatus::Processing)
            ->where('created_at', '<=', now()->subSeconds(self::MIN_AGE_SECONDS))
            ->orderBy('id')
            ->chunkById(100, function ($attempts) use (&$replayed, &$settled) {
                foreach ($attempts as $attempt) {
                    if (! $this->providers->isEnabled($attempt->provider)) {
                        continue;
                    }
                    $provider = $this->providers->get($attempt->provider);
                    if (! $provider instanceof RecoverableProvider
                        || $attempt->created_at->lt(now()->subMinutes($provider->maxPendingMinutes() + 60))) {
                        continue;
                    }

                    try {
                        if (! $attempt->provider_reference && $provider->canReplayInitiation()) {
                            $replayed += (int) $this->replay($attempt, $provider);
                        }
                        if ($attempt->provider_reference) {
                            $settled += (int) $this->checkStatus($attempt, $provider);
                        }
                    } catch (Throwable $e) {
                        Log::warning('Payment reconciliation failed', ['attempt' => $attempt->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return ['replayed' => $replayed, 'settled' => $settled];
    }

    private function replay(PaymentAttempt $attempt, RecoverableProvider $provider): bool
    {
        /** @var InitiationResult $result */
        $result = $this->providers->get($attempt->provider)->initiate($attempt, $attempt->intent);

        if ($result->providerReference) {
            $attempt->forceFill(['provider_reference' => $result->providerReference, 'error_kind' => null, 'message' => null])->save();

            return true;
        }

        if ($result->status === IntentStatus::Failed) {
            // Same key, definite rejection: the original request never created a payment.
            $attempt->forceFill(['status' => IntentStatus::Failed, 'error_kind' => 'definite', 'message' => $result->message])->save();
            $intent = $attempt->intent;
            if ($intent->status->isOpen() && $intent->attempts()->reorder('id', 'desc')->value('id') === $attempt->id) {
                $intent->forceFill(['status' => IntentStatus::Failed, 'failure_reason' => mb_substr((string) $result->message, 0, 255)])->save();
            }
        }

        return false;
    }

    private function checkStatus(PaymentAttempt $attempt, RecoverableProvider $provider): bool
    {
        $notification = $provider->fetchStatus($attempt, $attempt->intent);
        if (! $notification) {
            return false;
        }

        try {
            $event = InboundEvent::create([
                'provider' => $attempt->provider,
                'event_key' => mb_substr($notification->eventKey, 0, 190),
                'event_type' => $notification->eventType,
                'signature_ok' => true, // authenticated pull from the provider's API
                'payload' => $notification->payload,
                'payment_attempt_id' => $attempt->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        $this->processor->handle($event, $notification);

        return true;
    }
}
