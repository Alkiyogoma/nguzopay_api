<?php

namespace App\Domain\Business\Enums;

enum MemberStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
