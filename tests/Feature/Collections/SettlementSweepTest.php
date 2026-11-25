<?php

namespace Tests\Feature\Collections;

use App\Domain\Collections\Models\Settlement;
use App\Domain\Identity\Models\User;
use App\Domain\Ledger\Services\Ledger;
use App\Domain\Payouts\Models\Payout;
use App\Domain\Platform\Models\AuditLog;
use Illuminate\Support\Carbon;

/** D-029: the T+0 sweep pays each settlement as a payout; withdrawals and the sweep share one balance. */
class SettlementSweepTest extends CollectionsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->owner->forceFill(['two_factor_enabled' => true, 'phone' => '255700000099'])->save();
        config(['nguzopay.settlement.cutoff' => '15:00']);
        Carbon::setTestNow(Carbon::parse('2026-12-01 09:00', 'Africa/Dar_es_Salaam'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function collect(int $amount): void
    {
        $customer = $this->customer();
        $intent = $this->requestPayment(['customer_id' => $customer['id'], 'amount' => $amount])->json('data');
        $this->simulate($intent['id'])->assertOk();
    }

    private function setAccount(array $data = [])
    {
        return $this->putJson($this->url('payouts/settlement-account'), $data + [
            'channel' => 'bank', 'bank_name' => 'CRDB Bank', 'recipient_name' => 'Nguzo Academy Ltd', 'recipient_account' => '0150123456789',
        ]);
    }

    private function close(): void
    {
        $this->artisan('nguzopay:settlements:close', ['--date' => '2026-12-01'])->assertSuccessful();
    }

    public function test_the_sweep_pays_the_settlement_and_the_settlement_follows_its_payout(): void
    {
        $this->setAccount()->assertOk()->assertJsonPath('data.account_number', '••••••6789')->assertJsonPath('data.bank_name', 'CRDB Bank');
        $this->collect(80_000);
        $this->close();
        $this->close(); // idempotent

        $settlement = Settlement::sole();
        $payout = Payout::sole();
        $this->assertSame(['settlement', $settlement->id, 80_000, 0, '0150123456789', 'requested'], [
            $payout->kind, $payout->settlement_id, $payout->amount, $payout->fee, $payout->recipient_account, $payout->status,
        ]);

        // Reserved: the merchant can't also withdraw it.
        $this->getJson($this->url('payouts/balance'))->assertJsonPath('data.available', 0)->assertJsonPath('data.reserved', 80_000);
        // Nor cancel an automatic payout.
        $this->postJson($this->url("payouts/{$payout->id}/cancel"))->assertJsonValidationErrors('status');

        $this->signIn(User::factory()->admin()->withTwoFactor()->create());
        $this->postJson("/api/v1/admin/payouts/{$payout->id}/complete", ['external_reference' => 'CRDB-EFT-77'])->assertOk()->assertJsonPath('data.kind', 'settlement');

        $settlement->refresh();
        $this->assertSame(['paid', 'CRDB-EFT-77'], [$settlement->status, $settlement->payout_reference]);
        $ledger = app(Ledger::class);
        $this->assertSame(0, $ledger->balance($ledger->merchantPayable($this->business)));
        $this->assertLedgerBalanced();
    }

    public function test_money_already_withdrawn_is_never_paid_twice(): void
    {
        $this->setAccount();
        $this->collect(80_000);
        // The merchant withdraws 50,000 by hand before the cut-off.
        $this->postJson($this->url('payouts'), ['amount' => 50_000, 'channel' => 'mobile_money', 'recipient_name' => 'Asha', 'recipient_account' => '0689000809'])->assertCreated();

        $this->close();
        $sweep = Payout::where('kind', 'settlement')->sole();
        $this->assertSame(30_000, $sweep->amount); // only what is left

        // Everything already withdrawn: the settlement is covered, no payout.
        Carbon::setTestNow(Carbon::parse('2026-12-02 09:00', 'Africa/Dar_es_Salaam'));
        $this->collect(20_000);
        $this->postJson($this->url('payouts'), ['amount' => 20_000, 'channel' => 'mobile_money', 'recipient_name' => 'Asha', 'recipient_account' => '0689000809'])->assertCreated();
        $this->artisan('nguzopay:settlements:close', ['--date' => '2026-12-02'])->assertSuccessful();

        $this->assertSame('covered', Settlement::whereDate('settlement_date', '2026-12-02')->sole()->status);
        $this->assertSame(1, Payout::where('kind', 'settlement')->count());
        $this->getJson($this->url('payouts/balance'))->assertJsonPath('data.available', 0);
    }

    public function test_without_an_account_the_settlement_waits_and_a_failed_sweep_frees_the_money(): void
    {
        $this->collect(40_000);
        $this->close();
        $this->assertSame(0, Payout::count());
        $this->assertSame('pending_payout', Settlement::sole()->status);
        $this->assertSame(1, AuditLog::where('action', 'settlement.no_account')->count());

        // Next day, with an account: today's settlement and the waiting one are both swept.
        $this->setAccount(['channel' => 'mobile_money', 'recipient_account' => '0754000111', 'recipient_name' => 'Bursar', 'bank_name' => null]);
        Carbon::setTestNow(Carbon::parse('2026-12-02 09:00', 'Africa/Dar_es_Salaam'));
        $this->collect(10_000);
        $this->artisan('nguzopay:settlements:close', ['--date' => '2026-12-02'])->assertSuccessful();
        $this->assertSame([40_000, 10_000], Payout::orderBy('amount', 'desc')->pluck('amount')->all());
        $payout = Payout::where('amount', 10_000)->sole();
        $this->assertSame('mpesa', $payout->operator->value);

        // A failure leaves that money withdrawable by hand.

        $this->signIn(User::factory()->admin()->withTwoFactor()->create());
        $this->postJson("/api/v1/admin/payouts/{$payout->id}/fail", ['reason' => 'Wallet limit'])->assertOk();
        $this->assertSame('failed', $payout->settlement->fresh()->status);

        $this->signIn($this->owner);
        $this->getJson($this->url('payouts/balance'))->assertJsonPath('data.available', 10_000);
    }

    public function test_changing_the_settlement_account_needs_two_step_and_is_audited(): void
    {
        $this->setAccount()->assertOk();
        $this->setAccount(['recipient_account' => '0150999988887'])->assertOk();
        $this->setAccount(['channel' => 'mobile_money', 'recipient_account' => 'abc'])->assertJsonValidationErrors('recipient_account');

        $log = AuditLog::where('action', 'settlement_account.changed')->sole();
        $this->assertSame(['••••••6789 (CRDB Bank)', '••••••8887 (CRDB Bank)'], [$log->properties['from'], $log->properties['to']]);

        $this->owner->forceFill(['two_factor_enabled' => false])->save();
        $this->setAccount()->assertForbidden();
        $this->getJson($this->url('payouts/settlement-account'))->assertOk()->assertJsonPath('data.account_number', '••••••8887');
    }
}
