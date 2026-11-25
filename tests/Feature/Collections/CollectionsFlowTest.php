<?php

namespace Tests\Feature\Collections;

use App\Domain\Billing\Models\Customer;
use App\Domain\Business\Enums\StaffRole;
use App\Domain\Collections\Models\InboundEvent;
use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Models\PaymentAllocation;
use App\Domain\Collections\Models\PaymentAttempt;
use App\Domain\Collections\Providers\MockProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Ledger\Services\Ledger;
use Laravel\Sanctum\Sanctum;

class CollectionsFlowTest extends CollectionsTestCase
{
    public function test_partial_payments_fill_instalments_and_overpayment_becomes_credit(): void
    {
        config(['nguzopay.pricing.fee_bps' => 100]); // NguzoPay fee 1%, absorbed by the merchant

        $customer = $this->customer();
        $invoice = $this->invoice($customer['id'], 300_000, [
            'min_amount' => 50_000,
            'installments' => [['amount' => 150_000, 'due_date' => '2027-01-10'], ['amount' => 150_000, 'due_date' => '2027-04-10']],
        ]);
        $this->assertSame('INV-000001', $invoice['number']);
        $this->assertSame('2027-01-10', $invoice['due_date']);

        // First payment: 200,000 of 300,000.
        $intent = $this->requestPayment(['invoice_id' => $invoice['id'], 'amount' => 200_000])
            ->assertCreated()
            ->assertJsonPath('data.status', 'processing')       // the push went out (sync queue in tests)
            ->assertJsonPath('data.operator', 'tigo')            // 071… is a Mixx by Yas number
            ->assertJsonPath('data.attempts.0.provider', 'mock')
            ->json('data');

        $this->simulate($intent['id'])->assertOk()->assertJsonPath('data.status', 'succeeded');

        $this->getJson($this->url("invoices/{$invoice['id']}"))->assertOk()
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.amount_paid', 200_000)
            ->assertJsonPath('data.balance', 100_000)
            ->assertJsonPath('data.installments.0.status', 'paid')
            ->assertJsonPath('data.installments.1.amount_paid', 50_000);

        $payment = Payment::firstOrFail();
        $this->assertSame([2_000, 198_000], [$payment->platform_fee, $payment->net_amount]);

        // Second payment overpays by 50,000: the invoice is paid, the rest is credit.
        $second = $this->requestPayment(['invoice_id' => $invoice['id'], 'amount' => 150_000])->json('data');
        $this->simulate($second['id'])->assertJsonPath('data.status', 'succeeded');

        $this->getJson($this->url("invoices/{$invoice['id']}"))->assertJsonPath('data.status', 'paid')->assertJsonPath('data.balance', 0);
        $this->assertSame(50_000, Customer::find($customer['id'])->credit_balance);

        // The credit applies itself to the customer's next invoice.
        $next = $this->invoice($customer['id'], 80_000);
        $this->assertSame(50_000, $next['amount_paid']);
        $this->assertSame('partially_paid', $next['status']);
        $this->assertSame(0, Customer::find($customer['id'])->credit_balance);

        // Sub-ledger invariant: every shilling received is on an invoice or still credit.
        $this->assertSame(350_000, (int) PaymentAllocation::sum('amount'));

        // NguzoPay's books: the provider holds 350,000; we owe the merchant 346,500; 3,500 is our fee.
        $ledger = app(Ledger::class);
        $this->assertLedgerBalanced();
        $this->assertSame(350_000, $ledger->balance($ledger->providerClearing('mock')));
        $this->assertSame(346_500, $ledger->balance($ledger->merchantPayable($this->business)));
        $this->assertSame(3_500, $ledger->balance($ledger->feeRevenue()));

        $this->getJson($this->url('balance'))->assertOk()
            ->assertJsonPath('data.payable', 346_500)
            ->assertJsonPath('data.unsettled_count', 2);
    }

    public function test_webhooks_are_deduplicated_and_forged_ones_cannot_block_the_real_one(): void
    {
        $customer = $this->customer();
        $intent = $this->requestPayment(['customer_id' => $customer['id'], 'amount' => 20_000])->json('data');
        $webhook = $this->mockWebhook($intent['id']);

        // A forged copy (same body, wrong signature) is refused and stored without a de-dup key.
        $this->call('POST', '/api/v1/webhooks/mock', [], [], [], ['HTTP_X_MOCK_SIGNATURE' => str_repeat('0', 64), 'CONTENT_TYPE' => 'application/json'], $webhook['body'])
            ->assertStatus(401);

        $send = fn () => $this->call('POST', '/api/v1/webhooks/mock', [], [], [], ['HTTP_X_MOCK_SIGNATURE' => $webhook['signature'], 'CONTENT_TYPE' => 'application/json'], $webhook['body']);
        $send()->assertOk()->assertJsonPath('status', 'accepted');
        $send()->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, Payment::count());
        $this->assertSame(['processed', 'rejected'], InboundEvent::orderBy('status')->pluck('status')->all());
        // No invoice: the whole payment is customer credit.
        $this->assertSame(20_000, Customer::find($customer['id'])->credit_balance);
        $this->assertLedgerBalanced();
    }

    public function test_failed_request_can_be_retried_and_the_latest_attempt_wins(): void
    {
        $customer = $this->customer();
        $intent = $this->requestPayment(['customer_id' => $customer['id'], 'amount' => 10_000])->json('data');

        $this->simulate($intent['id'], 'failure')->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure_reason', 'The payer cancelled the request.');

        $this->postJson($this->url("payment-intents/{$intent['id']}/retry"))->assertOk()->assertJsonPath('data.status', 'processing');
        $this->simulate($intent['id'])->assertJsonPath('data.status', 'succeeded')->assertJsonCount(2, 'data.attempts')
            ->assertJsonPath('data.attempts.0.status', 'failed')
            ->assertJsonPath('data.attempts.1.status', 'succeeded');
        $this->assertSame(1, Payment::count());

        // A late failure for the first attempt cannot undo the success.
        $first = PaymentAttempt::where('payment_intent_id', $intent['id'])->orderBy('id')->first();
        $webhook = app(MockProvider::class)->simulate($first->load('intent'), 'failure');
        $this->call('POST', '/api/v1/webhooks/mock', [], [], [], ['HTTP_X_MOCK_SIGNATURE' => $webhook['signature'], 'CONTENT_TYPE' => 'application/json'], $webhook['body'])->assertOk();
        $this->getJson($this->url("payment-intents/{$intent['id']}"))->assertJsonPath('data.status', 'succeeded');
        $this->assertSame('ignored', InboundEvent::latest('id')->first()->status);
    }

    public function test_rejected_push_fails_at_once(): void
    {
        $customer = $this->customer();
        $this->requestPayment(['customer_id' => $customer['id'], 'amount' => 10_000, 'phone' => '0712345000'])
            ->assertCreated()->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.attempts.0.error_kind', 'definite');
    }

    public function test_a_success_after_expiry_still_records_the_money(): void
    {
        $customer = $this->customer();
        $intent = $this->requestPayment(['customer_id' => $customer['id'], 'amount' => 10_000])->json('data');

        $this->travel(31)->minutes();
        $this->artisan('nguzopay:payments:reconcile')->assertSuccessful();
        $this->getJson($this->url("payment-intents/{$intent['id']}"))->assertJsonPath('data.status', 'expired');

        $this->simulate($intent['id'])->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(1, Payment::count());
    }

    public function test_payment_rules(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer['id'], 100_000, ['min_amount' => 30_000]);
        $fullOnly = $this->invoice($customer['id'], 50_000, ['allow_partial' => false]);

        $this->requestPayment(['invoice_id' => $invoice['id'], 'amount' => 20_000])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->requestPayment(['invoice_id' => $fullOnly['id'], 'amount' => 20_000])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->requestPayment(['invoice_id' => $invoice['id'], 'amount' => 30_000, 'phone' => '123'])->assertUnprocessable()->assertJsonValidationErrors('phone');

        // Instalments must add up to the total.
        $this->postJson($this->url('invoices'), [
            'customer_id' => $customer['id'],
            'lines' => [['description' => 'Fees', 'unit_amount' => 100_000]],
            'installments' => [['amount' => 60_000], ['amount' => 30_000]],
        ])->assertUnprocessable()->assertJsonValidationErrors('installments');

        // Same external reference: the existing invoice comes back, nothing new is created.
        $first = $this->postJson($this->url('invoices'), ['customer_id' => $customer['id'], 'external_reference' => 'SIS-77', 'lines' => [['description' => 'A', 'unit_amount' => 5_000]]])->assertCreated();
        $this->postJson($this->url('invoices'), ['customer_id' => $customer['id'], 'external_reference' => 'SIS-77', 'lines' => [['description' => 'A', 'unit_amount' => 5_000]]])
            ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

        // Unverified businesses cannot collect live money.
        $this->business->forceFill(['status' => 'draft'])->save();
        $this->requestPayment(['invoice_id' => $invoice['id'], 'amount' => 30_000])->assertUnprocessable()->assertJsonValidationErrors('business');
    }

    public function test_permissions_and_tenancy(): void
    {
        $customer = $this->customer();

        $viewer = User::factory()->create();
        $this->business->members()->create(['user_id' => $viewer->id, 'role' => StaffRole::Viewer]);
        Sanctum::actingAs($viewer);
        $this->getJson($this->url('invoices'))->assertOk();
        $this->postJson($this->url('customers'), ['name' => 'X'])->assertForbidden();
        $this->requestPayment(['customer_id' => $customer['id'], 'amount' => 1_000])->assertForbidden();

        // Another business's staff cannot see or pay this business's records.
        $outsider = User::factory()->create();
        $other = $this->businessWith($outsider, StaffRole::Owner, ['status' => 'active']);
        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/businesses/{$other->id}/customers/{$customer['id']}")->assertNotFound();
        $this->postJson("/api/v1/businesses/{$other->id}/payment-intents", ['customer_id' => $customer['id'], 'amount' => 1_000, 'phone' => '0712345678'])
            ->assertUnprocessable();
    }

    public function test_void_only_without_payments(): void
    {
        $customer = $this->customer();
        $unpaid = $this->invoice($customer['id'], 10_000);
        $paid = $this->invoice($customer['id'], 10_000);
        $intent = $this->requestPayment(['invoice_id' => $paid['id'], 'amount' => 5_000])->json('data');
        $this->simulate($intent['id']);

        $this->postJson($this->url("invoices/{$unpaid['id']}/void"), ['reason' => 'Duplicate'])->assertOk()->assertJsonPath('data.status', 'void');
        $this->postJson($this->url("invoices/{$paid['id']}/void"), ['reason' => 'Oops'])->assertUnprocessable();
        $this->requestPayment(['invoice_id' => $unpaid['id'], 'amount' => 5_000])->assertUnprocessable()->assertJsonValidationErrors('invoice_id');
    }

    public function test_unknown_reference_is_kept_as_unmatched(): void
    {
        $body = json_encode(['id' => 'evt_x', 'type' => 'payment.succeeded', 'attempt_id' => '01JUNKNOWN0000000000000000', 'amount' => 5000]);
        $signature = hash_hmac('sha256', $body, (string) config('nguzopay.payments.mock.secret'));

        $this->call('POST', '/api/v1/webhooks/mock', [], [], [], ['HTTP_X_MOCK_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertSame('unmatched', InboundEvent::first()->status);
        $this->assertSame(0, Payment::count());
    }
}
