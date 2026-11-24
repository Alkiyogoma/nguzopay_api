<?php

namespace App\Domain\Business\Enums;

enum DocumentType: string
{
    case RegistrationCertificate = 'registration_certificate';
    case TinCertificate = 'tin_certificate';
    case BusinessLicence = 'business_licence';
    case DirectorId = 'director_id';
    case BankLetter = 'bank_letter';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::RegistrationCertificate => 'Certificate of registration / incorporation',
            self::TinCertificate => 'TIN certificate',
            self::BusinessLicence => 'Business licence',
            self::DirectorId => 'Director / trustee ID (NIDA, passport or voter ID)',
            self::BankLetter => 'Settlement bank account letter',
            self::Other => 'Other',
        };
    }

    /** Required before a business can submit for review. */
    public static function required(): array
    {
        return [
            self::RegistrationCertificate,
            self::TinCertificate,
            self::BusinessLicence,
            self::DirectorId,
            self::BankLetter,
        ];
    }
}
