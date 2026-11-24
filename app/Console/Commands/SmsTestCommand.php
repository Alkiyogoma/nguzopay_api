<?php

namespace App\Console\Commands;

use App\Domain\Identity\Support\PhoneNumber;
use App\Domain\Notifications\Sms\SmsDeliveryFailed;
use App\Domain\Notifications\Sms\SmsSender;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** Sends one SMS through the configured driver, to check DarSMS credentials and sender ID. */
class SmsTestCommand extends Command
{
    protected $signature = 'nguzopay:sms-test {phone : Tanzanian mobile, e.g. 0712345678} {--sender= : Sender ID (default SMS_DEFAULT_SENDER_ID)}';

    protected $description = 'Send a test SMS through the configured SMS driver (DarSMS or log)';

    public function handle(SmsSender $sms): int
    {
        try {
            $phone = PhoneNumber::normalize($this->argument('phone'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $sender = strtoupper($this->option('sender') ?: config('nguzopay.sms.default_sender_id'));
        $driver = config('nguzopay.sms.driver');

        $this->line("Driver: {$driver} · sender: {$sender} · to: {$phone}");

        try {
            $sms->send($sender, [$phone], 'NguzoPay test message. If you received this, SMS delivery works.');
        } catch (SmsDeliveryFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($driver === 'darsms' ? 'Accepted by DarSMS. Check the phone.' : 'Logged to storage/logs/laravel.log (SMS_DRIVER=log).');

        return self::SUCCESS;
    }
}
