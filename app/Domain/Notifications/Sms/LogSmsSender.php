<?php

namespace App\Domain\Notifications\Sms;

use Illuminate\Support\Facades\Log;

/** Local, CI and test driver. Keeps sent messages in memory so tests can read OTPs. */
class LogSmsSender implements SmsSender
{
    /** @var list<array{sender: string, to: list<string>, message: string}> */
    private array $sent = [];

    public function send(string $senderId, array $to, string $message): void
    {
        $this->sent[] = ['sender' => $senderId, 'to' => array_values($to), 'message' => $message];

        Log::info('SMS (log driver)', ['sender' => $senderId, 'to' => $to, 'message' => $message]);
    }

    /** @return list<array{sender: string, to: list<string>, message: string}> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function lastTo(string $phone): ?string
    {
        foreach (array_reverse($this->sent) as $sms) {
            if (in_array($phone, $sms['to'], true)) {
                return $sms['message'];
            }
        }

        return null;
    }
}
