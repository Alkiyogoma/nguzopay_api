<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Models\OtpCode;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Jobs\SendSms;
use Illuminate\Validation\ValidationException;

/**
 * SMS one-time codes. Only an HMAC of the code is stored; a new code
 * cancels earlier unused ones; each code allows a few wrong guesses.
 * The text goes out through the SMS queue, so DarSMS problems never fail the request.
 */
class OtpService
{
    public function send(string $phone, OtpPurpose $purpose, ?User $user = null, ?string $ip = null): OtpCode
    {
        OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->active()
            ->update(['consumed_at' => now()]);

        $length = (int) config('nguzopay.otp.length');
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
        $ttl = (int) config('nguzopay.otp.ttl_minutes');

        $otp = OtpCode::create([
            'phone' => $phone,
            'purpose' => $purpose,
            'user_id' => $user?->getKey(),
            'code_hash' => $this->hash($phone, $purpose, $code),
            'expires_at' => now()->addMinutes($ttl),
            'ip_address' => $ip,
        ]);

        SendSms::dispatch(
            config('nguzopay.sms.default_sender_id'),
            [$phone],
            "NguzoPay: Nambari yako ya uthibitisho ni {$code}. Inaisha baada ya dakika {$ttl}. Usimpe mtu yeyote. / Your verification code is {$code}.",
            "otp.{$purpose->value}",
        );

        return $otp;
    }

    /** @throws ValidationException */
    public function verify(string $phone, OtpPurpose $purpose, string $code, string $field = 'code'): OtpCode
    {
        $otp = OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->active()
            ->latest('id')
            ->first();

        if (! $otp || $otp->attempts >= (int) config('nguzopay.otp.max_attempts')) {
            throw ValidationException::withMessages([$field => 'This code is invalid or has expired. Request a new one.']);
        }

        $otp->increment('attempts');

        if (! hash_equals($otp->code_hash, $this->hash($phone, $purpose, trim($code)))) {
            throw ValidationException::withMessages([$field => 'This code is incorrect.']);
        }

        $otp->forceFill(['consumed_at' => now()])->save();

        return $otp;
    }

    private function hash(string $phone, OtpPurpose $purpose, string $code): string
    {
        return hash_hmac('sha256', "{$phone}|{$purpose->value}|{$code}", (string) config('app.key'));
    }
}
