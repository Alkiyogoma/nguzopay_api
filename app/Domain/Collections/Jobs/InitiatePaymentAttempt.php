<?php

namespace App\Domain\Collections\Jobs;

use App\Domain\Collections\Enums\IntentStatus;
use App\Domain\Collections\Models\PaymentAttempt;
use App\Domain\Collections\Providers\ProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Sends one push to the provider. Runs once: a second send could charge the
 * payer twice, so ambiguous outcomes wait for the webhook or the expiry instead.
 */
class InitiatePaymentAttempt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $attemptId)
    {
        $this->onQueue('payments');
    }

    public function handle(ProviderRegistry $providers): void
    {
        $attempt = PaymentAttempt::with('intent.customer', 'intent.business')->find($this->attemptId);

        if (! $attempt || $attempt->status !== IntentStatus::Pending || ! $attempt->intent->status->isOpen()) {
            return;
        }

        $result = $providers->get($attempt->provider)->initiate($attempt, $attempt->intent);

        DB::transaction(function () use ($attempt, $result) {
            $attempt = PaymentAttempt::whereKey($attempt->id)->lockForUpdate()->first();

            // The webhook can beat the provider's HTTP response; never overwrite its result.
            if ($attempt->status !== IntentStatus::Pending) {
                $attempt->forceFill(['provider_reference' => $attempt->provider_reference ?? $result->providerReference])->save();

                return;
            }

            $attempt->forceFill([
                'status' => $result->status,
                'provider_reference' => $result->providerReference ?? $attempt->provider_reference,
                'error_kind' => $result->errorKind,
                'message' => $result->message ? mb_substr($result->message, 0, 255) : null,
                'response' => $result->response ?: null,
            ])->save();

            $intent = $attempt->intent()->lockForUpdate()->first();
            if ($intent->status->isOpen()) {
                $intent->forceFill([
                    'status' => $result->status,
                    'failure_reason' => $result->status === IntentStatus::Failed ? mb_substr((string) $result->message, 0, 255) : null,
                ])->save();
            }
        });
    }
}
