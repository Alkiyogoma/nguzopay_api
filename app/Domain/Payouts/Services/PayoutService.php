<?php

namespace App\Domain\Payouts\Services;

use App\Domain\Business\Models\Business;
use App\Domain\Collections\Enums\MobileOperator;
use App\Domain\Collections\Models\Settlement;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\PhoneNumber;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Services\Ledger;
use App\Domain\Payouts\Models\Payout;
use App\Domain\Payouts\Models\SettlementAccount;
use App\Domain\Platform\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Withdrawals (D-027). The merchant can withdraw what NguzoPay owes it (the
 * merchant_payable ledger balance) minus withdrawals already requested. Until
 * the payout rails (P3), NGUZO finance pays each request and records the
 * transfer reference; only then does the ledger move.
 */
class PayoutService
{
    public function __construct(private readonly Ledger $ledger, private readonly AuditLogger $audit) {}

    /** @return array{owed: int, reserved: int, available: int, fee: int, min: int} */
    public function balance(Business $business): array
    {
        $owed = max(0, $this->ledger->balance($this->ledger->merchantPayable($business)));
        $reserved = (int) Payout::open()->where('business_id', $business->id)->sum(DB::raw('amount + fee'));

        return [
            'owed' => $owed,
            'reserved' => $reserved,
            'available' => max(0, $owed - $reserved),
            'fee' => $this->fee(),
            'min' => (int) config('nguzopay.payouts.min_amount'),
        ];
    }

    public function request(Business $business, array $data, User $actor): Payout
    {
        if (! empty($data['idempotency_key']) && ($existing = $business->payouts()->where('idempotency_key', $data['idempotency_key'])->first())) {
            return $existing;
        }
        if (! $business->canCollect()) {
            throw ValidationException::withMessages(['business' => 'Withdrawals open once NguzoPay has verified this business.']);
        }

        $destination = $this->destination($data);

        return DB::transaction(function () use ($business, $data, $actor, $destination) {
            // Serialise withdrawals per business so two requests can't both spend the same balance.
            Business::whereKey($business->id)->lockForUpdate()->first();
            $balance = $this->balance($business);
            $amount = (int) $data['amount'];

            if ($amount < $balance['min']) {
                throw ValidationException::withMessages(['amount' => 'The minimum withdrawal is TZS '.number_format($balance['min']).'.']);
            }
            if ($amount + $balance['fee'] > $balance['available']) {
                throw ValidationException::withMessages(['amount' => 'This is more than your available balance of TZS '.number_format($balance['available']).($balance['fee'] ? ' (including the TZS '.number_format($balance['fee']).' fee)' : '').'.']);
            }

            $payout = $business->payouts()->create($destination + [
                'reference' => $this->reference(),
                'amount' => $amount,
                'fee' => $balance['fee'],
                'narration' => isset($data['narration']) ? mb_substr(trim($data['narration']), 0, 140) : null,
                'status' => 'requested',
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'requested_by' => $actor->id,
            ]);

            $this->audit->record('payout.requested', $payout, [
                'reference' => $payout->reference, 'amount' => $amount, 'channel' => $payout->channel, 'account' => $payout->maskedAccount(),
            ], actor: $actor, business: $business);

            return $payout;
        });
    }

    /**
     * Where the sweep pays this business. Changing it is the classic fraud target,
     * so it needs 2FA (route) and is audited with the old and new masked account.
     */
    public function setSettlementAccount(Business $business, array $data, User $actor): SettlementAccount
    {
        $d = $this->destination($data);
        $before = $business->settlementAccount;

        $account = SettlementAccount::updateOrCreate(['business_id' => $business->id], [
            'channel' => $d['channel'],
            'operator' => $d['operator'],
            'bank_name' => $d['bank_name'],
            'account_name' => $d['recipient_name'],
            'account_number' => $d['recipient_account'],
            'updated_by' => $actor->id,
        ]);

        $this->audit->record($before ? 'settlement_account.changed' : 'settlement_account.set', $business, array_filter([
            'from' => $before ? $before->masked().' ('.($before->bank_name ?: $before->operator?->label()).')' : null,
            'to' => $account->masked().' ('.($account->bank_name ?: $account->operator?->label()).')',
        ]), actor: $actor);

        return $account;
    }

    /**
     * T+0 sweep (D-016 + D-029): pay a new settlement to the settlement account.
     * The amount is the settlement's net, capped at the available balance, so money
     * the merchant already withdrew by hand is never paid twice.
     * Returns null when there is no account yet (the settlement waits) or nothing left.
     */
    public function sweep(Settlement $settlement): ?Payout
    {
        return DB::transaction(function () use ($settlement) {
            $business = Business::whereKey($settlement->business_id)->lockForUpdate()->firstOrFail();
            if (Payout::where('settlement_id', $settlement->id)->exists()) {
                return Payout::where('settlement_id', $settlement->id)->first();
            }

            $account = $business->settlementAccount;
            if (! $account) {
                $this->audit->record('settlement.no_account', $settlement, ['net_amount' => $settlement->net_amount], business: $business);

                return null;
            }

            $amount = min($settlement->net_amount, $this->balance($business)['available']);
            if ($amount <= 0) {
                $settlement->forceFill(['status' => 'covered', 'paid_at' => now()])->save();
                $this->audit->record('settlement.covered', $settlement, ['reason' => 'already withdrawn'], business: $business);

                return null;
            }

            $payout = $business->payouts()->create([
                'kind' => 'settlement',
                'settlement_id' => $settlement->id,
                'reference' => $this->reference(),
                'amount' => $amount,
                'fee' => 0, // NguzoPay's fee was taken from each payment (D-015)
                'channel' => $account->channel,
                'operator' => $account->operator,
                'bank_name' => $account->bank_name,
                'recipient_name' => $account->account_name,
                'recipient_account' => $account->account_number,
                'narration' => 'Settlement '.$settlement->settlement_date->format('j M Y'),
                'status' => 'requested',
            ]);

            $this->audit->record('payout.requested', $payout, [
                'reference' => $payout->reference, 'amount' => $amount, 'kind' => 'settlement', 'settlement' => $settlement->id, 'account' => $payout->maskedAccount(),
            ], business: $business);

            return $payout;
        });
    }

    public function cancel(Payout $payout, User $actor): Payout
    {
        if ($payout->kind === 'settlement') {
            throw ValidationException::withMessages(['status' => 'Settlement payouts are sent automatically and can\'t be cancelled.']);
        }

        return $this->transition($payout, ['requested'], 'cancelled', $actor, 'payout.cancelled');
    }

    /** NGUZO finance has started the transfer. */
    public function markProcessing(Payout $payout, User $admin): Payout
    {
        return $this->transition($payout, ['requested'], 'processing', $admin, 'payout.processing');
    }

    /** The money left: book it (merchant payable down, payout float down; fee to revenue). */
    public function complete(Payout $payout, string $externalReference, User $admin): Payout
    {
        return DB::transaction(function () use ($payout, $externalReference, $admin) {
            $payout = $this->transition($payout, ['requested', 'processing'], 'completed', $admin, 'payout.completed', ['external_reference' => $externalReference]);

            $this->ledger->post('payout', $payout, [
                [$this->ledger->merchantPayable($payout->business_id), $payout->total(), 0],
                [$this->ledger->account('payout_float', AccountType::Asset), 0, $payout->amount],
                [$this->ledger->feeRevenue(), 0, $payout->fee],
            ], "Payout {$payout->reference}");

            $payout->settlement?->forceFill(['status' => 'paid', 'payout_reference' => $externalReference, 'paid_at' => now()])->save();

            return $payout;
        });
    }

    /** The money stays owed and becomes available to withdraw by hand. */
    public function fail(Payout $payout, string $reason, User $admin): Payout
    {
        $payout = $this->transition($payout, ['requested', 'processing'], 'failed', $admin, 'payout.failed', ['failure_reason' => mb_substr($reason, 0, 255)]);
        $payout->settlement?->forceFill(['status' => 'failed'])->save();

        return $payout;
    }

    private function transition(Payout $payout, array $from, string $to, User $actor, string $action, array $extra = []): Payout
    {
        return DB::transaction(function () use ($payout, $from, $to, $actor, $action, $extra) {
            $payout = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if (! in_array($payout->status, $from, true)) {
                throw ValidationException::withMessages(['status' => "This withdrawal is {$payout->status} and can't be changed that way."]);
            }

            $payout->forceFill($extra + ['status' => $to] + ($to === 'cancelled' ? [] : ['processed_by' => $actor->id, 'processed_at' => now()]))->save();
            $this->audit->record($action, $payout, array_filter(['reference' => $payout->reference] + $extra), actor: $actor, business: $payout->business);

            return $payout;
        });
    }

    /** @return array<string, mixed> */
    private function destination(array $data): array
    {
        if ($data['channel'] === 'mobile_money') {
            try {
                $phone = PhoneNumber::normalize((string) $data['recipient_account']);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['recipient_account' => 'Enter a valid Tanzanian mobile number.']);
            }
            $operator = ! empty($data['operator']) ? MobileOperator::from($data['operator']) : MobileOperator::fromPhone($phone);
            if (! $operator) {
                throw ValidationException::withMessages(['operator' => 'Choose the mobile network.']);
            }

            return ['channel' => 'mobile_money', 'operator' => $operator, 'recipient_account' => $phone, 'recipient_name' => trim($data['recipient_name']), 'bank_name' => null];
        }

        $account = preg_replace('/\s+/', '', (string) $data['recipient_account']);
        if (! preg_match('/^[0-9A-Za-z]{6,30}$/', $account)) {
            throw ValidationException::withMessages(['recipient_account' => 'Enter a valid bank account number.']);
        }

        return ['channel' => 'bank', 'operator' => null, 'bank_name' => trim((string) $data['bank_name']), 'recipient_account' => $account, 'recipient_name' => trim($data['recipient_name'])];
    }

    private function fee(): int
    {
        return (int) config('nguzopay.payouts.fee_fixed');
    }

    private function reference(): string
    {
        do {
            $ref = 'PO'.strtoupper(Str::random(10));
        } while (Payout::where('reference', $ref)->exists());

        return $ref;
    }
}
