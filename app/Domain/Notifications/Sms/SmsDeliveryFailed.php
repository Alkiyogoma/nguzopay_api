<?php

namespace App\Domain\Notifications\Sms;

use RuntimeException;

class SmsDeliveryFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
