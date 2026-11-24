<?php

namespace App\Domain\Notifications\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * DarSMS: POST {base}/integrations/sms/send
 *   body {"senderId": "…", "to": ["2557…"], "message": "…"}
 * Auth is either `X-API-Key: <key>` (default) or `Authorization: Bearer <key>` (DARSMS_AUTH=bearer).
 * 5xx/429/timeouts are retryable; other 4xx (bad number, unapproved sender) are not.
 */
class DarSmsSender implements SmsSender
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout = 10,
        private readonly string $auth = 'api_key',
    ) {}

    public function send(string $senderId, array $to, string $message): void
    {
        if (! $this->apiKey) {
            throw new SmsDeliveryFailed('DarSMS API key is not configured (DARSMS_API_KEY).');
        }

        $request = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);

        $request = $this->auth === 'bearer'
            ? $request->withToken($this->apiKey)
            : $request->withHeaders(['X-API-Key' => $this->apiKey]);

        try {
            $response = $request->post('/integrations/sms/send', [
                'senderId' => $senderId,
                'to' => array_values($to),
                'message' => $message,
            ]);
        } catch (ConnectionException $e) {
            throw new SmsDeliveryFailed('DarSMS did not respond: '.$e->getMessage(), retryable: true);
        }

        if ($response->failed()) {
            throw new SmsDeliveryFailed(
                "DarSMS rejected the message (HTTP {$response->status()}): ".$this->reason($response),
                retryable: $response->serverError() || $response->status() === 429,
            );
        }
    }

    /** DarSMS's own error text, shortened. Never includes our key. */
    private function reason(Response $response): string
    {
        $text = $response->json('message') ?? $response->json('error') ?? $response->body();
        $text = is_string($text) ? $text : json_encode($text);

        return Str::limit(str_replace((string) $this->apiKey, '***', (string) $text), 300);
    }
}
