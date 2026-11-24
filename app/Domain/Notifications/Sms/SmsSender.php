<?php

namespace App\Domain\Notifications\Sms;

interface SmsSender
{
    /**
     * @param  list<string>  $to  Numbers normalised to 255XXXXXXXXX
     *
     * @throws SmsDeliveryFailed
     */
    public function send(string $senderId, array $to, string $message): void;
}
