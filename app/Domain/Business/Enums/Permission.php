<?php

namespace App\Domain\Business\Enums;

/** What a staff member may do inside one business. Later phases add payments, payouts, API keys. */
enum Permission: string
{
    case BusinessView = 'business.view';
    case BusinessUpdate = 'business.update';
    case KycManage = 'kyc.manage';
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';
    case AuditView = 'audit.view';
}
