<?php

namespace App\Domain\Business\Enums;

enum SenderIdStatus: string
{
    case Requested = 'requested';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
