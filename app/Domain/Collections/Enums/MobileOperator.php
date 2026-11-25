<?php

namespace App\Domain\Collections\Enums;

enum MobileOperator: string
{
    case Mpesa = 'mpesa';
    case Airtel = 'airtel';
    case Tigo = 'tigo';         // Mixx by Yas
    case Halopesa = 'halopesa';
    case Azampesa = 'azampesa';

    public function label(): string
    {
        return match ($this) {
            self::Mpesa => 'M-Pesa',
            self::Airtel => 'Airtel Money',
            self::Tigo => 'Mixx by Yas',
            self::Halopesa => 'HaloPesa',
            self::Azampesa => 'AzamPesa',
        };
    }

    /**
     * Network from a normalised 255XXXXXXXXX number, by TCRA prefix.
     * Ported numbers keep their old prefix, so the payer can always override.
     */
    public static function fromPhone(string $msisdn): ?self
    {
        $prefix = substr($msisdn, 3, 2);

        return match ($prefix) {
            '74', '75', '76' => self::Mpesa,
            '68', '69', '78' => self::Airtel,
            '65', '67', '71', '77' => self::Tigo,
            '61', '62' => self::Halopesa,
            default => null,
        };
    }

    /** Free-text operator names from providers ("Mpesa", "Tigo", "airtel", "vodacom"). */
    public static function tryFromProvider(?string $name): ?self
    {
        $name = strtolower(preg_replace('/[^a-z]/i', '', (string) $name));

        return match (true) {
            in_array($name, ['mpesa', 'vodacom', 'mpesatz'], true) => self::Mpesa,
            str_starts_with($name, 'airtel') => self::Airtel,
            in_array($name, ['tigo', 'tigopesa', 'mixx', 'mixxbyyas', 'yas'], true) => self::Tigo,
            str_starts_with($name, 'halo') => self::Halopesa,
            str_starts_with($name, 'azam') => self::Azampesa,
            default => null,
        };
    }
}
