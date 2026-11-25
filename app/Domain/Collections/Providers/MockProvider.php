<?php

namespace App\Domain\Collections\Providers;

use App\Domain\Collections\Enums\IntentStatus;
use App\Domain\Collections\Models\PaymentAttempt;
use App\Domain\Collections\Models\PaymentIntent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Local and CI stand-in for a mobile-money provider. Never enabled in production.
 * A payer number ending in 000 is rejected at once; otherwise the push "goes out"
 * and the result arrives through a signed webhook (see simulate()).
 */
class MockProvider implements CollectionProvider
{
    public function code(): string
    {
        return 'mock';
    }

    public function limits(): array
    {
        return ['min' => 100, 'max' => 10_000_000];
    }

    public function requiresOperator(): bool
    {
        return false;
    }

    public function initiate(PaymentAttempt $attempt, PaymentIntent $intent): InitiationResult
    {
        if (str_ends_with($intent->payer_phone, '000')) {
            return InitiationResult::failed('The mock network rejected this number.');
        }

        return InitiationResult::sent('mock_'.Str::lower($attempt->id), ['status' => 'pending']);
    }

    /**
     * Build the webhook the mock "network" would send.
     *
     * @return array{body: string, signature: string}
     */
    public function simulate(PaymentAttempt $attempt, string $outcome, ?int $amount = null): array
    {
        $body = json_encode([
            'id' => 'mock_evt_'.Str::lower((string) Str::ulid()),
            'type' => $outcome === 'success' ? 'payment.succeeded' : 'payment.failed',
            'attempt_id' => $attempt->id,
            'reference' => $attempt->provider_reference,
            'amount' => $amount ?? $attempt->intent->amount,
            'fee' => 0,
            'reason' => $outcome === 'success' ? null : 'The payer cancelled the request.',
        ], JSON_UNESCAPED_SLASHES);

        return ['body' => $body, 'signature' => $this->sign($body)];
    }

    public function parseNotification(Request $request): ProviderNotification
    {
        $raw = $request->getContent();

        if (! hash_equals($this->sign($raw), (string) $request->header('X-Mock-Signature'))) {
            throw new InvalidNotification('Bad mock signature.');
        }

        $data = json_decode($raw, true) ?: [];

        return new ProviderNotification(
            eventKey: (string) ($data['id'] ?? ''),
            eventType: $data['type'] ?? null,
            attemptId: $data['attempt_id'] ?? null,
            providerReference: $data['reference'] ?? null,
            outcome: match ($data['type'] ?? null) {
                'payment.succeeded' => IntentStatus::Succeeded,
                'payment.failed' => IntentStatus::Failed,
                default => null,
            },
            amount: isset($data['amount']) ? (int) $data['amount'] : null,
            providerFee: isset($data['fee']) ? (int) $data['fee'] : null,
            failureReason: $data['reason'] ?? null,
            payload: $data,
        );
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, (string) config('nguzopay.payments.mock.secret'));
    }
}
