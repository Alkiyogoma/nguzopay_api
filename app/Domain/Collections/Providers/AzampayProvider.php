<?php

namespace App\Domain\Collections\Providers;

use App\Domain\Collections\Enums\IntentStatus;
use App\Domain\Collections\Enums\MobileOperator;
use App\Domain\Collections\Models\PaymentAttempt;
use App\Domain\Collections\Models\PaymentIntent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Azampay MNO checkout (USSD push). Notes and caveats: DEVELOPMENT_PLAN §4 "Azampay".
 */
class AzampayProvider implements CollectionProvider
{
    private const TOKEN_CACHE = 'azampay:token';

    private const KEY_CACHE = 'azampay:callback-public-key';

    public function code(): string
    {
        return 'azampay';
    }

    public function limits(): array
    {
        return ['min' => 100, 'max' => 5_000_000];
    }

    public function requiresOperator(): bool
    {
        return true;
    }

    public function initiate(PaymentAttempt $attempt, PaymentIntent $intent): InitiationResult
    {
        $operator = $intent->operator ?? MobileOperator::fromPhone($intent->payer_phone);
        if (! $operator) {
            return InitiationResult::failed("The payer's network is unknown.");
        }

        $payload = [
            'accountNumber' => $intent->payer_phone,
            // Azampay's samples send the amount as a string.
            'amount' => (string) $intent->amount,
            'currency' => 'TZS',
            'externalId' => $attempt->id,
            'provider' => $this->operatorName($operator),
            'additionalProperties' => ['intent_id' => $intent->id, 'merchant' => $intent->business->code],
        ];

        try {
            $response = $this->send(fn (PendingRequest $http) => $http->post('/azampay/mno/checkout', $payload));
        } catch (ConnectionException) {
            return InitiationResult::ambiguous('No answer from Azampay; waiting for confirmation.');
        } catch (RuntimeException $e) {
            return InitiationResult::failed($e->getMessage());
        }

        $body = $response->json() ?? [];

        if ($response->successful() && ($body['success'] ?? false)) {
            return InitiationResult::sent($body['transactionId'] ?? null, $body, $body['message'] ?? null);
        }

        $message = 'Azampay: '.($body['message'] ?? $body['title'] ?? $response->reason());

        // success=false on a 200, or a 400 validation error: definite. 5xx or timeout: ambiguous.
        return $response->successful() || $response->clientError()
            ? InitiationResult::failed($message, $body)
            : InitiationResult::ambiguous($message, $body);
    }

    public function parseNotification(Request $request): ProviderNotification
    {
        $data = json_decode($request->getContent(), true) ?: $request->all();
        $data = array_change_key_case($data, CASE_LOWER);
        $signature = (string) ($data['signature'] ?? '');

        if ($signature === '') {
            if (! config('nguzopay.payments.azampay.allow_unsigned') || app()->isProduction()) {
                throw new InvalidNotification('Azampay callback has no signature.');
            }
        } elseif (! $this->verify($data, $signature)) {
            throw new InvalidNotification('Bad Azampay signature.');
        }

        $status = strtolower((string) ($data['transactionstatus'] ?? ''));
        $reference = $data['reference'] ?? $data['transid'] ?? $data['externalreference'] ?? null;

        return new ProviderNotification(
            eventKey: ($reference ?? $data['utilityref'] ?? '').':'.$status,
            eventType: 'checkout.'.$status,
            attemptId: $data['utilityref'] ?? null,
            providerReference: $reference,
            outcome: match ($status) {
                'success', 'successful', 'completed' => IntentStatus::Succeeded,
                'failure', 'failed', 'fail' => IntentStatus::Failed,
                default => null,
            },
            amount: isset($data['amount']) && is_numeric($data['amount']) ? (int) round((float) $data['amount']) : null,
            providerFee: null, // not reported; estimated from config until statements (P4)
            operator: MobileOperator::tryFromProvider($data['operator'] ?? null),
            payerPhone: isset($data['msisdn']) ? ltrim((string) $data['msisdn'], '+') : null,
            failureReason: $status === 'success' ? null : ($data['message'] ?? null),
            payload: array_diff_key($data, array_flip(['password', 'user'])),
        );
    }

    /**
     * RSA-SHA256 (PKCS#1 v1.5) over the concatenated fields. Azampay's docs give two
     * different field lists, so the configured list is tried first, then the short one.
     * On failure the cached key is re-fetched once, as Azampay recommends.
     */
    private function verify(array $data, string $signature): bool
    {
        $raw = base64_decode($signature, true);
        if ($raw === false) {
            return false;
        }

        $candidates = array_unique([
            implode('', array_map(fn ($f) => (string) ($data[$f] ?? ''), config('nguzopay.payments.azampay.signature_fields'))),
            ($data['utilityref'] ?? '').($data['externalreference'] ?? ''),
        ]);

        foreach ([false, true] as $refresh) {
            $pem = $this->publicKey($refresh);
            if (! $pem || ! ($key = openssl_pkey_get_public($pem))) {
                continue;
            }
            foreach ($candidates as $signed) {
                if (openssl_verify($signed, $raw, $key, OPENSSL_ALGO_SHA256) === 1) {
                    return true;
                }
            }
            if (config('nguzopay.payments.azampay.public_key')) {
                break; // pinned key: nothing to refresh
            }
        }

        return false;
    }

    private function publicKey(bool $refresh): ?string
    {
        if ($pinned = config('nguzopay.payments.azampay.public_key')) {
            return str_replace('\n', "\n", (string) $pinned);
        }

        if ($refresh) {
            Cache::forget(self::KEY_CACHE);
        }

        return Cache::remember(self::KEY_CACHE, now()->addDay(), function () {
            try {
                $response = $this->send(fn (PendingRequest $http) => $http->get('/azampay/v1/public-key', ['format' => 'Pem']));
            } catch (\Throwable) {
                return null;
            }

            return $response->successful() ? ($response->json('publicKey') ?: null) : null;
        }) ?: null;
    }

    /** Authenticated call with one token refresh on 401. */
    private function send(callable $call): Response
    {
        $response = $call($this->http($this->token()));

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE);
            $response = $call($this->http($this->token()));
        }

        return $response;
    }

    private function http(string $token): PendingRequest
    {
        $config = config('nguzopay.payments.azampay');

        return Http::baseUrl($config['base_url'])
            ->withToken($token)
            ->withHeaders(['X-API-Key' => (string) $config['api_key']])
            ->acceptJson()->asJson()
            ->timeout($config['timeout']);
    }

    private function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE);
        if ($cached) {
            return $cached;
        }

        $config = config('nguzopay.payments.azampay');
        $response = Http::baseUrl($config['auth_url'])->acceptJson()->asJson()->timeout($config['timeout'])
            ->post('/AppRegistration/GenerateToken', [
                'appName' => $config['app_name'],
                'clientId' => $config['client_id'],
                'clientSecret' => $config['client_secret'],
            ]);

        $token = $response->json('data.accessToken');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('Azampay rejected NguzoPay\'s credentials ('.$response->status().').');
        }

        // Expiry format is not documented: refresh well inside an hour, and on any 401.
        Cache::put(self::TOKEN_CACHE, $token, now()->addMinutes(50));

        return $token;
    }

    private function operatorName(MobileOperator $operator): string
    {
        return match ($operator) {
            MobileOperator::Mpesa => 'Mpesa',
            MobileOperator::Airtel => 'Airtel',
            MobileOperator::Tigo => 'Tigo',
            MobileOperator::Halopesa => 'Halopesa',
            MobileOperator::Azampesa => 'Azampesa',
        };
    }
}
