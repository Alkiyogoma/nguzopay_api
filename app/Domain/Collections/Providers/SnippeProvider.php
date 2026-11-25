<?php

namespace App\Domain\Collections\Providers;

use App\Domain\Collections\Enums\IntentStatus;
use App\Domain\Collections\Enums\MobileOperator;
use App\Domain\Collections\Models\PaymentAttempt;
use App\Domain\Collections\Models\PaymentIntent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Snippe mobile-money collections (API version 2026-01-25).
 * Notes and caveats: DEVELOPMENT_PLAN §4 "Snippe". Behaviour learned in
 * production by the DarSMS integration is ported here (status mapping,
 * signature formats, idempotent replay, validated status lookups).
 */
class SnippeProvider implements CollectionProvider, RecoverableProvider
{
    /** Webhooks older than this are refused (replay protection). */
    private const TOLERANCE_SECONDS = 300;

    public function code(): string
    {
        return 'snippe';
    }

    public function limits(): array
    {
        return ['min' => 500, 'max' => 5_000_000];
    }

    public function requiresOperator(): bool
    {
        return false; // Snippe detects the network from the number
    }

    public function maxPendingMinutes(): int
    {
        return 240; // Snippe expires a payment after 4 hours
    }

    public function canReplayInitiation(): bool
    {
        return true; // same Idempotency-Key + same body returns Snippe's original response (24 h)
    }

    public function initiate(PaymentAttempt $attempt, PaymentIntent $intent): InitiationResult
    {
        $config = config('nguzopay.payments.snippe');
        [$first, $last] = $intent->customer->nameParts();

        // Saved before sending and reused on replay: same key + different body is a 422 at Snippe.
        $payload = $attempt->request ?: [
            'payment_type' => 'mobile',
            'details' => ['amount' => $intent->amount, 'currency' => 'TZS'],
            'phone_number' => $intent->payer_phone,
            'customer' => [
                'firstname' => $first,
                'lastname' => $last,
                'email' => $intent->customer->email ?: ($intent->business->email ?: $config['fallback_email']),
            ],
            'webhook_url' => config('nguzopay.payments.webhook_base_url').'/api/v1/webhooks/snippe',
            // A lookup hint only: the reference Snippe returns is what binds a callback.
            'metadata' => [
                'attempt_id' => $attempt->id,
                'intent_id' => $intent->id,
                'merchant' => $intent->business->code,
            ],
        ];
        if (! $attempt->request) {
            $attempt->forceFill(['request' => $payload])->save();
        }

        try {
            // The attempt id (26-char ULID) fits Snippe's 30-character key limit; a longer key returns PAY_001.
            $response = $this->http()->withHeaders(['Idempotency-Key' => $attempt->id])->post('/v1/payments', $payload);
        } catch (ConnectionException) {
            return InitiationResult::ambiguous('No answer from Snippe; waiting for confirmation.');
        }

        $body = $response->json() ?? [];
        $reference = $body['data']['reference'] ?? null;

        if ($response->successful() && is_string($reference) && $reference !== '') {
            return InitiationResult::sent($reference, $body['data']);
        }

        $message = 'Snippe: '.($body['message'] ?? $response->reason());

        // A 2xx without a reference is malformed, so we cannot know the outcome.
        if ($response->successful()) {
            return InitiationResult::ambiguous($message, $body);
        }

        // 4xx (validation, auth, scope) is a definite no. 422 means this key was already used with
        // another body, so the original may exist; 429/5xx may have gone through.
        return $response->clientError() && ! in_array($response->status(), [422, 429], true)
            ? InitiationResult::failed($message, $body)
            : InitiationResult::ambiguous($message, $body);
    }

    public function fetchStatus(PaymentAttempt $attempt, PaymentIntent $intent): ?ProviderNotification
    {
        if (! $attempt->provider_reference) {
            return null;
        }

        try {
            $response = $this->http()->get('/v1/payments/'.rawurlencode($attempt->provider_reference));
        } catch (ConnectionException) {
            return null;
        }

        $data = $response->json('data');
        if (! $response->successful() || ! is_array($data)) {
            return null;
        }

        // Never trust a status that is not about exactly this payment.
        if (($data['reference'] ?? null) !== $attempt->provider_reference
            || ($data['amount']['currency'] ?? null) !== 'TZS'
            || (int) ($data['amount']['value'] ?? -1) !== $intent->amount) {
            Log::warning('Snippe status does not match the saved payment', ['attempt' => $attempt->id]);

            return null;
        }

        $outcome = $this->outcome(null, $data);
        if ($outcome === null) {
            return null;
        }

        return new ProviderNotification(
            eventKey: "status:{$attempt->provider_reference}:{$outcome->value}",
            eventType: 'status.'.strtolower((string) ($data['status'] ?? '')),
            attemptId: $attempt->id,
            providerReference: $attempt->provider_reference,
            outcome: $outcome,
            amount: (int) $data['amount']['value'],
            providerFee: isset($data['settlement']['fees']['value']) ? (int) $data['settlement']['fees']['value'] : null,
            operator: MobileOperator::tryFromProvider($data['channel']['provider'] ?? null),
            failureReason: $data['failure_reason'] ?? null,
            payload: $data,
            currency: 'TZS',
        );
    }

    public function resendPush(PaymentAttempt $attempt, PaymentIntent $intent): bool
    {
        if (! $attempt->provider_reference) {
            return false;
        }

        try {
            return $this->http()
                ->post('/v1/payments/'.rawurlencode($attempt->provider_reference).'/push', ['phone' => $intent->payer_phone])
                ->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function parseNotification(Request $request): ProviderNotification
    {
        $secret = (string) config('nguzopay.payments.snippe.webhook_secret');
        if ($secret === '') {
            throw new InvalidNotification('Snippe webhook secret is not configured.');
        }

        $raw = $request->getContent();
        $this->verifySignature($raw, (string) $request->header('X-Webhook-Signature'), $request->header('X-Webhook-Timestamp'), $secret);

        $body = json_decode($raw, true) ?: [];

        // 2026-01-25 envelope {id, type, data}; legacy 2026-01-01 is flat {event, ...}.
        $type = $body['type'] ?? $body['event'] ?? null;
        $data = $body['data'] ?? $body;
        $fee = $data['settlement']['fees']['value'] ?? $data['payment_fee'] ?? null;
        $outcome = $this->outcome($type, $data);

        return new ProviderNotification(
            eventKey: (string) ($body['id'] ?? (($data['reference'] ?? '').':'.($type ?? $data['status'] ?? ''))),
            eventType: $type ?? (isset($data['status']) ? 'payment.'.$data['status'] : null),
            attemptId: $data['metadata']['attempt_id'] ?? null,
            providerReference: $data['reference'] ?? null,
            outcome: $outcome,
            amount: isset($data['amount']['value']) ? (int) $data['amount']['value'] : null,
            providerFee: $fee !== null ? (int) $fee : null,
            operator: MobileOperator::tryFromProvider($data['channel']['provider'] ?? $data['payment_channel'] ?? null),
            payerPhone: isset($data['customer']['phone']) ? ltrim((string) $data['customer']['phone'], '+') : null,
            failureReason: $data['failure_reason'] ?? null,
            payload: $body,
            currency: $data['amount']['currency'] ?? null,
            referenceMustMatch: true,
        );
    }

    /**
     * Snippe signs hex(HMAC_SHA256(secret, "{timestamp}.{rawBody}")). Legacy callbacks
     * (no timestamp header) sign the body alone. The header may carry a "sha256=" prefix,
     * several comma-separated values, or base64 instead of hex.
     */
    private function verifySignature(string $raw, string $header, ?string $timestamp, string $secret): void
    {
        if ($timestamp !== null && $timestamp !== '') {
            if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
                throw new InvalidNotification('Snippe webhook timestamp is invalid or too old.');
            }
            $expected = hash_hmac('sha256', "{$timestamp}.{$raw}", $secret, true);
        } elseif (config('nguzopay.payments.snippe.accept_legacy_signature')) {
            $expected = hash_hmac('sha256', $raw, $secret, true);
        } else {
            throw new InvalidNotification('Snippe webhook timestamp is missing.');
        }

        foreach ($this->signatureCandidates($header) as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return;
            }
        }

        throw new InvalidNotification('Bad Snippe signature.');
    }

    /** @return list<string> raw signature bytes decoded from hex or base64 */
    private function signatureCandidates(string $header): array
    {
        $candidates = [];

        foreach (array_filter(array_map('trim', explode(',', $header))) as $part) {
            $part = trim($part, '"');
            if (preg_match('/^(sha256|v1|signature|sig|hmac)\s*=\s*(.+)$/i', $part, $m)) {
                $part = trim($m[2], '"');
            }

            if (preg_match('/^[0-9a-f]+$/i', $part) && strlen($part) % 2 === 0) {
                $candidates[] = hex2bin($part);
            }
            $b64 = strtr(preg_replace('/^base64:/i', '', $part), '-_', '+/');
            if (preg_match('/^[A-Za-z0-9+\/]+=*$/', $b64) && ($decoded = base64_decode($b64.str_repeat('=', (4 - strlen($b64) % 4) % 4), true)) !== false) {
                $candidates[] = $decoded;
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Map an event type, else the payment status. Snippe reports an expired
     * payment as "cancelled" with failure_code "expired"; both are final.
     */
    private function outcome(?string $type, array $data): ?IntentStatus
    {
        $expired = strtolower((string) ($data['failure_code'] ?? '')) === 'expired';

        $byType = match ($type) {
            'payment.completed' => IntentStatus::Succeeded,
            'payment.failed' => IntentStatus::Failed,
            'payment.expired' => IntentStatus::Expired,
            'payment.voided', 'payment.cancelled', 'payment.canceled' => $expired ? IntentStatus::Expired : IntentStatus::Cancelled,
            default => null,
        };
        if ($byType !== null || ($type !== null && str_starts_with($type, 'payout.'))) {
            return $byType; // payout.* arrive in P3
        }

        return match (strtolower((string) ($data['status'] ?? ''))) {
            'completed', 'success', 'successful' => IntentStatus::Succeeded,
            'failed', 'declined' => IntentStatus::Failed,
            'expired' => IntentStatus::Expired,
            'voided', 'cancelled', 'canceled' => $expired ? IntentStatus::Expired : IntentStatus::Cancelled,
            default => null, // pending; "reversed" is handled as an event needing review
        };
    }

    private function http(): PendingRequest
    {
        $config = config('nguzopay.payments.snippe');

        return Http::baseUrl($config['base_url'])
            ->withToken((string) $config['api_key'])
            ->acceptJson()->asJson()
            ->timeout($config['timeout']);
    }
}
