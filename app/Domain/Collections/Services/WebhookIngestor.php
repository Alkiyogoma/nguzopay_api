<?php

namespace App\Domain\Collections\Services;

use App\Domain\Collections\Jobs\ProcessInboundEvent;
use App\Domain\Collections\Models\InboundEvent;
use App\Domain\Collections\Providers\InvalidNotification;
use App\Domain\Collections\Providers\ProviderRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Provider webhook intake: verify, store, de-duplicate, acknowledge, then
 * process on the queue. Rejected events are kept without a de-duplication key,
 * so a forged copy can never block the genuine event.
 */
class WebhookIngestor
{
    private const REDACT = ['password', 'user', 'clientSecret', 'client_secret'];

    public function __construct(private readonly ProviderRegistry $providers) {}

    /** @return array{status: string, http: int} */
    public function ingest(string $provider, Request $request): array
    {
        $adapter = $this->providers->get($provider);
        $payload = Arr::except(json_decode($request->getContent(), true) ?: $request->all(), self::REDACT);

        try {
            $notification = $adapter->parseNotification($request);
        } catch (InvalidNotification $e) {
            InboundEvent::create([
                'provider' => $provider,
                'event_key' => null,
                'signature_ok' => false,
                'payload' => $payload,
                'status' => 'rejected',
                'error' => mb_substr($e->getMessage(), 0, 255),
                'ip_address' => $request->ip(),
            ]);

            return ['status' => 'rejected', 'http' => 401];
        }

        try {
            $event = InboundEvent::create([
                'provider' => $provider,
                'event_key' => mb_substr($notification->eventKey, 0, 190),
                'event_type' => $notification->eventType,
                'signature_ok' => true,
                'payload' => $payload,
                'ip_address' => $request->ip(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return ['status' => 'duplicate', 'http' => 200];
        }

        ProcessInboundEvent::dispatch($event->id, $notification);

        return ['status' => 'accepted', 'http' => 200];
    }
}
