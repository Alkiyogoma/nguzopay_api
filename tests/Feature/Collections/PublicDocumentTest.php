<?php

namespace Tests\Feature\Collections;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Collections\Models\Payment;

/** The QR code on printed invoices and receipts opens a public page for that one document. */
class PublicDocumentTest extends CollectionsTestCase
{
    public function test_an_invoice_link_shows_live_status_balance_and_how_to_pay_without_signing_in(): void
    {
        $customer = $this->customer(['name' => 'Asha Mussa', 'email' => 'asha@example.test']);
        $invoice = $this->invoice($customer['id'], 100_000, ['allow_partial' => true]);
        $url = $invoice['public_url'];
        $this->assertMatchesRegularExpression('#/i/[A-Za-z0-9]{32}$#', $url);
        $token = basename($url);

        // Part-pay it.
        $intent = $this->requestPayment(['invoice_id' => $invoice['id'], 'amount' => 40_000])->json('data');
        $this->simulate($intent['id'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $res = $this->getJson("/api/v1/public/invoices/{$token}")->assertOk();
        $res->assertJsonPath('data.invoice.status', 'partially_paid')
            ->assertJsonPath('data.invoice.balance', 60_000)
            ->assertJsonPath('data.invoice.amount_paid', 40_000)
            ->assertJsonPath('data.invoice.customer.name', 'Asha Mussa')
            ->assertJsonPath('data.business.code', $this->business->code)
            ->assertJsonCount(1, 'data.invoice.payments');
        $this->assertNotNull($res->json('data.invoice.control_number'));
        $this->assertStringContainsString('/r/', $res->json('data.invoice.payments.0.receipt_url'));

        // Nothing personal beyond the name, and no internal ids.
        $this->assertStringNotContainsString('asha@example.test', $res->getContent());
        $this->assertStringNotContainsString('255712345678', $res->getContent());
        $this->assertStringNotContainsString($invoice['id'], $res->getContent());
    }

    public function test_a_receipt_link_verifies_the_payment_and_links_its_invoices(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer['id'], 30_000);
        $intent = $this->requestPayment(['invoice_id' => $invoice['id'], 'amount' => 30_000])->json('data');
        $this->simulate($intent['id'])->assertOk();
        $payment = Payment::sole();

        $receiptUrl = $this->getJson($this->url("payments/{$payment->id}"))->json('data.public_url');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $this->getJson('/api/v1/public/receipts/'.basename($receiptUrl))->assertOk()
            ->assertJsonPath('data.receipt.receipt_number', $payment->receipt_number)
            ->assertJsonPath('data.receipt.status', 'succeeded')
            ->assertJsonPath('data.receipt.amount', 30_000)
            ->assertJsonPath('data.receipt.invoices.0.status', 'paid')
            ->assertJsonPath('data.receipt.invoices.0.url', Invoice::sole()->publicUrl())
            ->assertJsonCount(1, 'data.receipt.invoices');
    }

    public function test_unknown_or_malformed_tokens_reveal_nothing(): void
    {
        $this->getJson('/api/v1/public/invoices/'.str_repeat('a', 32))->assertNotFound();
        $this->getJson('/api/v1/public/invoices/short')->assertNotFound();
        $this->getJson('/api/v1/public/receipts/'.str_repeat('b', 32))->assertNotFound();
    }

    public function test_invoices_created_before_tokens_get_one_when_needed(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer['id'], 5_000);
        Invoice::whereKey($invoice['id'])->update(['public_token' => null]);

        $url = $this->getJson($this->url("invoices/{$invoice['id']}"))->json('data.public_url');
        $this->getJson('/api/v1/public/invoices/'.basename($url))->assertOk();
    }
}
