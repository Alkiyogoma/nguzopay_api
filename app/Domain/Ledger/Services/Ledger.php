<?php

namespace App\Domain\Ledger\Services;

use App\Domain\Business\Models\Business;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * NguzoPay's own books, double entry. Chart of accounts (P1):
 *  - provider_clearing:{provider}  asset      what a provider holds for us (gross − provider fee)
 *  - merchant_payable:{business}   liability  what we owe a merchant until the sweep
 *  - fee_revenue                   revenue    NguzoPay's fee
 * Merchant invoices and customer credit are the merchant's sub-ledger (Billing), not ours.
 */
class Ledger
{
    public function providerClearing(string $provider): LedgerAccount
    {
        return $this->account("provider_clearing:{$provider}", AccountType::Asset);
    }

    public function merchantPayable(Business|string $business): LedgerAccount
    {
        $id = $business instanceof Business ? $business->getKey() : $business;

        return $this->account("merchant_payable:{$id}", AccountType::Liability, $id);
    }

    public function feeRevenue(): LedgerAccount
    {
        return $this->account('fee_revenue', AccountType::Revenue);
    }

    public function account(string $key, AccountType $type, ?string $businessId = null): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(['key' => $key], ['type' => $type, 'business_id' => $businessId]);
    }

    /**
     * Post one balanced entry. Each line is [LedgerAccount, debit, credit]; zero lines are dropped.
     * Posting the same (source, kind) twice returns the first entry unchanged.
     *
     * @param  list<array{0: LedgerAccount, 1: int, 2: int}>  $lines
     */
    public function post(string $kind, Model $source, array $lines, ?string $description = null): LedgerEntry
    {
        $lines = array_values(array_filter($lines, fn ($l) => $l[1] > 0 || $l[2] > 0));

        $debits = array_sum(array_column($lines, 1));
        $credits = array_sum(array_column($lines, 2));

        foreach ($lines as [$account, $debit, $credit]) {
            if ($debit < 0 || $credit < 0 || ($debit > 0 && $credit > 0)) {
                throw new InvalidArgumentException('Each ledger line is a positive debit or a positive credit.');
            }
        }

        if ($debits === 0 || $debits !== $credits) {
            throw new InvalidArgumentException("Unbalanced ledger entry {$kind}: debits {$debits}, credits {$credits}.");
        }

        return DB::transaction(function () use ($kind, $source, $lines, $description) {
            $sourceType = class_basename($source);
            $existing = LedgerEntry::where(['source_type' => $sourceType, 'source_id' => (string) $source->getKey(), 'kind' => $kind])->first();

            if ($existing) {
                return $existing;
            }

            $entry = LedgerEntry::create([
                'kind' => $kind,
                'source_type' => $sourceType,
                'source_id' => (string) $source->getKey(),
                'description' => $description,
                'posted_at' => now(),
            ]);

            foreach ($lines as [$account, $debit, $credit]) {
                $entry->lines()->create(['ledger_account_id' => $account->getKey(), 'debit' => $debit, 'credit' => $credit]);
                $delta = $account->type->isDebitNormal() ? $debit - $credit : $credit - $debit;
                LedgerAccount::whereKey($account->getKey())->increment('balance', $delta);
            }

            return $entry;
        });
    }

    public function balance(LedgerAccount $account): int
    {
        return (int) LedgerAccount::whereKey($account->getKey())->value('balance');
    }
}
