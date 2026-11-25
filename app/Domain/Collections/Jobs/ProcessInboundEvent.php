<?php

namespace App\Domain\Collections\Jobs;

use App\Domain\Collections\Models\InboundEvent;
use App\Domain\Collections\Providers\ProviderNotification;
use App\Domain\Collections\Services\PaymentProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessInboundEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $eventId, public readonly ProviderNotification $notification)
    {
        $this->onQueue('payments');
    }

    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function handle(PaymentProcessor $processor): void
    {
        $event = InboundEvent::find($this->eventId);

        if ($event && ! in_array($event->status, ['processed', 'ignored'], true)) {
            $processor->handle($event, $this->notification);
        }
    }

    public function failed(?Throwable $e): void
    {
        InboundEvent::whereKey($this->eventId)->update(['status' => 'failed', 'error' => mb_substr((string) $e?->getMessage(), 0, 255)]);
        Log::error('Provider event could not be processed', ['event_id' => $this->eventId, 'error' => $e?->getMessage()]);
    }
}
