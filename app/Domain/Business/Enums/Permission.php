<?php

namespace App\Domain\Business\Enums;

/** What a staff member may do inside one business. Later phases add payouts and API keys. */
enum Permission: string
{
    case BusinessView = 'business.view';
    case BusinessUpdate = 'business.update';
    case KycManage = 'kyc.manage';
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';
    case AuditView = 'audit.view';
    case PaymentsView = 'payments.view';       // customers, invoices, payments, settlements
    case PaymentsCollect = 'payments.collect'; // add customers and invoices, request payments
    case BillingManage = 'billing.manage';     // void invoices, apply credit
    case PayoutsRequest = 'payouts.request';   // withdraw the available balance
    case ApiKeysManage = 'api_keys.manage';    // create and revoke API keys
}
