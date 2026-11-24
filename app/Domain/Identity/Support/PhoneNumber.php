<?php

namespace App\Domain\Identity\Support;

use InvalidArgumentException;

/**
 * Tanzanian mobile numbers, always stored as 255XXXXXXXXX (12 digits).
 * Accepts 07XXXXXXXX, 7XXXXXXXX, 2557XXXXXXXX and +255 7XX XXX XXX.
 */
final class PhoneNumber
{
    public static function normalize(string $input): string
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '255'.substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $digits = '255'.$digits;
        }

        if (! preg_match('/^255[67]\d{8}$/', $digits)) {
            throw new InvalidArgumentException('Enter a valid Tanzanian mobile number, for example 0712 345 678.');
        }

        return $digits;
    }

    public static function tryNormalize(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        try {
            return self::normalize($input);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** 255712345678 → +255 7•• ••• 678 */
    public static function mask(string $phone): string
    {
        return '+255 '.substr($phone, 3, 1).'•• ••• '.substr($phone, -3);
    }
}
