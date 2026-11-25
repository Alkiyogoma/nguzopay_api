<?php

namespace App\Domain\Collections\Providers;

use App\Domain\Collections\Enums\IntentStatus;
use App\Domain\Collections\Enums\MobileOperator;
use App\Domain\Collections\Models\PaymentAttempt;
use App\Domain\Collections\Models\PaymentIntent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Selcom Checkout API (https://developers.selcommobile.com/#checkout-api), the
 * same flow zanur runs in production for order payments:
 *  1. POST /v1/checkout/create-order-minimal  (order_id = our attempt id)
 *  2. POST /v1/checkout/wallet-payment        (USSD push to the payer)
 *  3. Selcom calls the order's webhook; GET /v1/checkout/order-status confirms.
 *
 * Selcom does not document how to verify its webhook signature, so a webhook
 * is never trusted on its own: it must carry our per-order URL token, and the
 * outcome, amount and references are always read back from order-status.
 * A Selcom order can be paid once, so replaying initiation can never charge twice.
 */
class SelcomProvider implements CollectionProvider, RecoverableProvider
{
    /** Result codes (docs "API Response"): 000 success; 111/927 in progress; 999 ambiguous; anything else failed. */
    private const IN_PROGRESS = ['111', '927'];

    private const AMBIGUOUS = '999';

    public function code(): string
    {
        return 'selcom';
    }

    public function limits(): array
    {
        return ['min' => 100, 'max' => 5_000_000];
    }

    public function requiresOperator(): bool
    {
        return false; // Selcom routes the push by the payer's number
    }

    public function maxPendingMinutes(): int
    {
        return (int) config('nguzopay.payments.selcom.expiry_minutes');
    }

    public function canReplayInitiation(): bool
    {
        return true; // the order id is our attempt id, and an order is paid at most once
    }

    public function initiate(PaymentAttempt $attempt, PaymentIntent $intent): InitiationResult
    {
        $order = $attempt->request ?: $this->orderPayload($attempt, $intent);
        if (! $attempt->request) {
            $attempt->forceFill(['request' => $order])->save();
        }

        try {
            $created = $this->call('post', '/v1/checkout/create-order-minimal', $order);
        } catch (ConnectionException) {
            return InitiationResult::ambiguous('No answer from Selcom; waiting for confirmation.');
        }

        $body = $created->json() ?? [];
        $code = (string) ($body['resultcode'] ?? '');

        if ($code !== '000') {
            // A replay after an unanswered create: the order may already exist at Selcom.
            $existing = $this->orderStatus($attempt->id);
            if (! $existing || (int) round((float) ($existing['amount'] ?? -1)) !== $intent->amount) {
                $message = 'Selcom: '.($body['message'] ?? $created->reason());

                return $this->isDefinite($created, $code)
                    ? InitiationResult::failed($message, $body)
                    : InitiationResult::ambiguous($message, $body);
            }
        }

        return $this->push($attempt, $intent, $attempt->id);
    }

    /**
     * Selcom's webhook is a lookup hint. The URL token proves we issued it for
     * this order; order-status (an authenticated pull) supplies the outcome.
     */
    public function parseNotification(Request $request): ProviderNotification
    {
        $data = json_decode($request->getContent(), true) ?: $request->all();
        $orderId = (string) ($data['order_id'] ?? '');

        if ($orderId === '' || ! hash_equals($this->webhookToken($orderId), (string) $request->query('token'))) {
            throw new InvalidNotification('Selcom webhook is not for an order we created.');
        }

        $status = $this->orderStatus($orderId);
        if (! $status) {
            // Not lost: the reconciler reads order-status again later.
            throw new InvalidNotification('Selcom order status could not be confirmed.');
        }

        return $this->notification($orderId, $status, 'webhook.');
    }

    public function fetchStatus(PaymentAttempt $attempt, PaymentIntent $intent): ?ProviderNotification
    {
        $status = $this->orderStatus($attempt->id);
        if (! $status) {
            return null;
        }

        if ((int) round((float) ($status['amount'] ?? -1)) !== $intent->amount) {
            Log::warning('Selcom status does not match the saved payment', ['attempt' => $attempt->id]);

            return null;
        }

        $notification = $this->notification($attempt->id, $status, 'status.');

        return $notification->outcome ? $notification : null;
    }

    public function resendPush(PaymentAttempt $attempt, PaymentIntent $intent): bool
    {
        if (! $attempt->provider_reference) {
            return false;
        }

        // Each push needs its own transid; the order (and its single payment) stays the same.
        $result = $this->push($attempt, $intent, $attempt->id.'R'.now()->format('His'));

        return $result->status === IntentStatus::Processing && $result->errorKind === null;
    }

    private function orderPayload(PaymentAttempt $attempt, PaymentIntent $intent): array
    {
        $config = config('nguzopay.payments.selcom');
        $customer = $intent->customer;

        return [
            'vendor' => (string) $config['vendor'],
            'order_id' => $attempt->id,
            'buyer_email' => (string) ($customer->email ?: ($intent->business->email ?: $config['fallback_email'])),
            'buyer_name' => mb_substr((string) ($customer->name ?: $intent->business->displayName()), 0, 100),
            'buyer_phone' => $intent->payer_phone,
            'amount' => $intent->amount,
            'currency' => 'TZS',
            'webhook' => base64_encode($this->webhookUrl($attempt->id)),
            'buyer_remarks' => 'None',
            'merchant_remarks' => mb_substr($intent->business->code.' '.($intent->invoice?->number ?? 'Account'), 0, 100),
            'no_of_items' => 1,
            'expiry' => (int) $config['expiry_minutes'],
        ];
    }

    /** USSD push for the order. The order id is the provider reference from here on. */
    private function push(PaymentAttempt $attempt, PaymentIntent $intent, string $transid): InitiationResult
    {
        try {
            $response = $this->call('post', '/v1/checkout/wallet-payment', [
                'transid' => $transid,
                'order_id' => $attempt->id,
                'msisdn' => $intent->payer_phone,
            ]);
        } catch (ConnectionException) {
            // The order exists, so its status can be read; the prompt may not have gone out.
            return InitiationResult::sent($attempt->id, [], 'No answer from Selcom to the prompt; waiting for confirmation.');
        }

        $body = $response->json() ?? [];
        $code = (string) ($body['resultcode'] ?? '');

        if ($code === '000' || in_array($code, self::IN_PROGRESS, true) || ! $this->isDefinite($response, $code)) {
            return InitiationResult::sent($attempt->id, $body, $body['message'] ?? null);
        }

        $this->cancel($attempt->id);

        return InitiationResult::failed('Selcom: '.($body['message'] ?? $response->reason()), $body);
    }

    /** @return array<string, mixed>|null the order, or null when Selcom cannot be asked or does not know it */
    private function orderStatus(string $orderId): ?array
    {
        try {
            $response = $this->call('get', '/v1/checkout/order-status', ['order_id' => $orderId]);
        } catch (ConnectionException) {
            return null;
        }

        $order = $response->json('data.0');

        return $response->successful() && ($response->json('resultcode') === '000')
            && is_array($order) && (string) ($order['order_id'] ?? '') === $orderId ? $order : null;
    }

    private function notification(string $orderId, array $status, string $typePrefix): ProviderNotification
    {
        $state = strtoupper((string) ($status['payment_status'] ?? ''));
        $outcome = match ($state) {
            'COMPLETED', 'COMPLETE' => IntentStatus::Succeeded,
            'CANCELLED', 'USERCANCELLED', 'USERCANCELED', 'REJECTED', 'FAILED', 'FAIL' => IntentStatus::Failed,
            default => null, // PENDING, INPROGRESS
        };
        $phone = $status['phone'] ?? $status['msisdn'] ?? null;

        return new ProviderNotification(
            // Same key for webhook and status pull, so the outcome is processed once.
            eventKey: "order:{$orderId}:".($outcome?->value ?? strtolower($state)),
            eventType: $typePrefix.strtolower($state),
            attemptId: $orderId,
            providerReference: $orderId,
            outcome: $outcome,
            amount: is_numeric($status['amount'] ?? null) ? (int) round((float) $status['amount']) : null,
            providerFee: null, // not reported; estimated from config until statements (P4)
            operator: MobileOperator::tryFromProvider(preg_replace('/TZ$/i', '', (string) ($status['channel'] ?? ''))),
            payerPhone: $phone ? ltrim((string) $phone, '+') : null,
            failureReason: $outcome === IntentStatus::Failed ? 'Selcom: '.strtolower($state) : null,
            payload: $status,
            currency: 'TZS',
        );
    }

    private function cancel(string $orderId): void
    {
        try {
            $this->call('delete', '/v1/checkout/cancel-order', ['order_id' => $orderId]);
        } catch (\Throwable) {
            // Best effort: an unpaid order also expires on its own.
        }
    }

    /** A 4xx, or a 2xx with a failure result code. 5xx, timeouts and 999 may have gone through. */
    private function isDefinite(Response $response, string $code): bool
    {
        if ($response->serverError() || $code === self::AMBIGUOUS || in_array($code, self::IN_PROGRESS, true)) {
            return false;
        }

        return $response->clientError() || ($response->successful() && $code !== '');
    }

    /**
     * Signed call, as in Selcom's own PHP client (selcom-apigw-client): HMAC-SHA256
     * over "timestamp=<ts>&k1=v1&k2=v2" in Signed-Fields order, keyed with the API secret.
     * POST sends JSON; GET and DELETE send the same fields as the query string.
     */
    private function call(string $method, string $path, array $data): Response
    {
        $config = config('nguzopay.payments.selcom');
        $timestamp = now('Africa/Dar_es_Salaam')->format('c');
        $fields = array_keys($data);

        $signed = 'timestamp='.$timestamp;
        foreach ($fields as $field) {
            $signed .= '&'.$field.'='.$data[$field];
        }

        $http = Http::baseUrl($config['base_url'])
            ->acceptJson()->asJson()
            ->timeout($config['timeout'])
            ->withHeaders([
                'Authorization' => 'SELCOM '.base64_encode((string) $config['api_key']),
                'Digest-Method' => 'HS256',
                'Digest' => base64_encode(hash_hmac('sha256', $signed, (string) $config['api_secret'], true)),
                'Timestamp' => $timestamp,
                'Signed-Fields' => implode(',', $fields),
            ]);

        return match ($method) {
            'post' => $http->post($path, $data),
            'get' => $http->get($path, $data),
            'delete' => $http->delete($path.'?'.http_build_query($data)),
        };
    }

    private function webhookUrl(string $orderId): string
    {
        return config('nguzopay.payments.webhook_base_url').'/api/v1/webhooks/selcom?token='.$this->webhookToken($orderId);
    }

    private function webhookToken(string $orderId): string
    {
        $secret = (string) (config('nguzopay.payments.selcom.webhook_secret') ?: config('app.key'));

        return hash_hmac('sha256', 'selcom-order:'.$orderId, $secret);
    }
}
