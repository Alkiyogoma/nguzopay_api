<?php

namespace App\Domain\Notifications\Jobs;

use App\Domain\Notifications\Sms\SmsDeliveryFailed;
use App\Domain\Notifications\Sms\SmsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One SMS, sent from the "sms" queue so a slow or failing DarSMS never breaks a request.
 * Retryable failures (timeouts, 5xx, 429) back off and retry; a definite rejection
 * (bad number, unapproved sender) fails at once. Rate-limited below DarSMS's 180/min.
 */
class SendSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @param list<string> $to */
    public function __construct(
        public readonly string $senderId,
        public readonly array $to,
        public readonly string $message,
        /** Safe label for logs (never the message: it may hold an OTP). */
        public readonly string $purpose = 'sms',
    ) {
        $this->onQueue('sms');
    }

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [10, 30, 60, 180];
    }

    public function middleware(): array
    {
        return [new RateLimited('darsms')];
    }

    public function handle(SmsSender $sms): void
    {
        try {
            $sms->send($this->senderId, $this->to, $this->message);
        } catch (SmsDeliveryFailed $e) {
            if (! $e->retryable) {
                $this->fail($e);

                return;
            }
            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('SMS not delivered', [
            'purpose' => $this->purpose,
            'sender' => $this->senderId,
            'recipients' => count($this->to),
            'error' => $e?->getMessage(),
        ]);
    }
}
