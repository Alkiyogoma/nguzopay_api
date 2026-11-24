<?php

namespace App\Domain\Business\Enums;

/** Who registered the business. Churches are not on BRELA; SACCOs register with TCDC. */
enum RegistrationAuthority: string
{
    case Brela = 'brela';
    case Rita = 'rita';
    case Tcdc = 'tcdc';
    case Moha = 'moha';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Brela => 'BRELA (companies, business names)',
            self::Rita => 'RITA (trustees)',
            self::Tcdc => 'TCDC (cooperatives, SACCOs)',
            self::Moha => 'Ministry of Home Affairs (societies, religious organisations)',
            self::Other => 'Other',
        };
    }
}
