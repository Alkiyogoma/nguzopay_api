<?php

namespace App\Domain\Business\Enums;

/** Sector. Drives defaults (fee bearer, partial payments) in later phases. */
enum BusinessType: string
{
    case School = 'school';
    case Microfinance = 'mfi';
    case Sacco = 'sacco';
    case Church = 'church';
    case Hospital = 'hospital';
    case Shop = 'shop';
    case Aggregator = 'aggregator';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::School => 'School',
            self::Microfinance => 'Microfinance institution',
            self::Sacco => 'SACCO',
            self::Church => 'Church / religious organisation',
            self::Hospital => 'Hospital / clinic',
            self::Shop => 'Shop / retail',
            self::Aggregator => 'Aggregator / platform',
            self::Other => 'Other',
        };
    }
}
