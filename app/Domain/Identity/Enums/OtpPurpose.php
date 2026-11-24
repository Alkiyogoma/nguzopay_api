<?php

namespace App\Domain\Identity\Enums;

enum OtpPurpose: string
{
    /** Passwordless sign-in (creates the account on first use). */
    case Login = 'login';
    /** Proving ownership of a phone number added to a profile. */
    case VerifyPhone = 'verify_phone';
    /** Second factor after password, Google or GitHub sign-in. */
    case TwoFactor = 'two_factor';
}
