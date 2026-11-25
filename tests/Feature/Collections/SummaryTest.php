<?php

namespace Tests\Feature\Collections;

class SummaryTest extends CollectionsTestCase
{
    public function test_home_summary_counts_this_period(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer['id'], 100_000);
        foreach ([30_000, 20_000] as $amount) {
            $intent = $this->requestPayment(['invoice_id' => $invoice['id'], 'amount' => $amount])->json('data');
            $this->simulate($intent['id']);
        }
        $failed = $this->requestPayment(['invoice_id' => $invoice['id'], 'amount' => 10_000])->json('data');
        $this->simulate($failed['id'], 'failure');

        $this->getJson($this->url('summary?metric=revenue&period=7d'))->assertOk()
            ->assertJsonPath('data.headline', 50_000)
            ->assertJsonPath('data.stats.payments', 2)
            ->assertJsonPath('data.stats.outstanding', 50_000)
            ->assertJsonPath('data.has_data', true);
        // 6-hour buckets from six days ago up to now: 25 to 28 depending on the time of day.
        $this->assertThat(count($this->getJson($this->url('summary'))->json('data.series')), $this->logicalAnd($this->greaterThanOrEqual(25), $this->lessThanOrEqual(28)));

        $this->getJson($this->url('summary?metric=success&period=30d'))->assertJsonPath('data.headline', 66.7)->assertJsonCount(30, 'data.series');
        $this->getJson($this->url('summary?metric=customers'))->assertJsonPath('data.headline', 1);
        $this->getJson($this->url('summary?metric=nope'))->assertUnprocessable();
    }
}
