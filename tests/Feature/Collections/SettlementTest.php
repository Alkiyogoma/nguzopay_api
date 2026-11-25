<?php

namespace Tests\Feature\Collections;

use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Models\Settlement;
use Illuminate\Support\Carbon;

class SettlementTest extends CollectionsTestCase
{
    public function test_t0_sweep_groups_the_days_payments_once(): void
    {
        config(['nguzopay.pricing.fee_bps' => 100, 'nguzopay.settlement.cutoff' => '15:00']);
        Carbon::setTestNow(Carbon::parse('2026-12-01 09:00', 'Africa/Dar_es_Salaam'));

        $customer = $this->customer();
        foreach ([40_000, 60_000] as $amount) {
            $intent = $this->requestPayment(['customer_id' => $customer['id'], 'amount' => $amount])->json('data');
            $this->simulate($intent['id']);
        }

        // A payment after the cut-off waits for tomorrow's sweep.
        Carbon::setTestNow(Carbon::parse('2026-12-01 16:00', 'Africa/Dar_es_Salaam'));
        $late = $this->requestPayment(['customer_id' => $customer['id'], 'amount' => 10_000])->json('data');
        $this->simulate($late['id']);

        $this->artisan('nguzopay:settlements:close', ['--date' => '2026-12-01'])->assertSuccessful();
        $this->artisan('nguzopay:settlements:close', ['--date' => '2026-12-01'])->assertSuccessful();

        $settlement = Settlement::sole();
        $this->assertSame([2, 100_000, 1_000, 99_000], [
            $settlement->payments_count, $settlement->gross_amount, $settlement->platform_fees, $settlement->net_amount,
        ]);
        $this->assertSame(1, Payment::whereNull('settlement_id')->count());

        $this->getJson($this->url('settlements'))->assertOk()
            ->assertJsonPath('data.0.net_amount', 99_000)->assertJsonPath('data.0.status', 'pending_payout');

        Carbon::setTestNow();
    }
}
