<?php

namespace App\Domain\Ledger\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Revenue = 'revenue';
    case Expense = 'expense';

    /** Assets and expenses grow with debits; liabilities and revenue with credits. */
    public function isDebitNormal(): bool
    {
        return in_array($this, [self::Asset, self::Expense], true);
    }
}
