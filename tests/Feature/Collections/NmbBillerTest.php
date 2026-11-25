<?php

namespace Tests\Feature\Collections;

use App\Domain\Billing\Models\Customer;
use App\Domain\Collections\Models\Payment;
use App\Domain\Ledger\Services\Ledger;
use Illuminate\Testing\TestResponse;

class NmbBillerTest extends CollectionsTestCase
{
    private const TOKEN = 'nmb-test-token-123';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'nguzopay.payments.nmb.bearer_token' => self::TOKEN,
            'nguzopay.payments.nmb.collection_account' => '23100000001',
            'nguzopay.payments.nmb.product_code' => 'NGZP',
            'nguzopay.payments.nmb.min_amount' => 1000,
        ]);
    }

    private function nmb(string $path, array $body, string $token = self::TOKEN): TestResponse
    {
        return $this->postJson("/api/v1/billers/nmb/{$path}", $body, ['Authorization' => "Bearer {$token}"]);
    }

    private function inquiry(string $reference): TestResponse
    {
        return $this->nmb('inquiry', ['body' => ['paymentReference' => $reference, 'transactionId' => 'GWX21567289'],
            'reservedFields' => [['fieldName' => 'a', 'fieldValue' => 'b']]]);
    }

    private function notify(string $reference, string $amount, string $transactionId = 'GWX9876738', string $bankReference = 'NMB123467587'): TestResponse
    {
        return $this->nmb('payment-notification', ['requestDetails' => [
            'channelId' => 'INTERNET BANKING',
            'transactionId' => $transactionId,
            'paymentReference' => $reference,
            'amount' => $amount,
            'bankReference' => $bankReference,
            'payerDetails' => ['name' => 'William Frank', 'mobileNumber' => '0658123693'],
            'effectiveDate' => '2026-11-25 12:30:00',
        ]]);
    }

    private function invoiceWithControl(string $customerId, int $amount, array $extra = []): array
    {
        return $this->getJson($this->url('invoices/'.$this->invoice($customerId, $amount, $extra)['id']))->json('data');
    }

    public function test_bearer_token_is_required(): void
    {
        $customer = $this->customer();

        $this->nmb('inquiry', ['body' => ['paymentReference' => $customer['account_number'], 'transactionId' => 'X']], 'wrong')
            ->assertStatus(401)->assertJsonPath('statusCode', 649);

        config(['nguzopay.payments.nmb.bearer_token' => null]);
        $this->inquiry($customer['account_number'])->assertStatus(401);
    }

    public function test_inquiry_maps_bills_to_nmb_payment_options(): void
    {
        $customer = $this->customer(['name' => 'Feza Nursery', 'email' => 'bursar@feza.tz']);
        $partial = $this->invoiceWithControl($customer['id'], 900_000, ['min_amount' => 50_000]);
        $exact = $this->invoiceWithControl($customer['id'], 30_000, ['allow_partial' => false]);

        $this->inquiry($partial['control_number'])->assertOk()
            ->assertJsonPath('statusCode', 600)
            ->assertJsonPath('message', 'SUCCESS')
            ->assertJsonPath('body.paymentReference', $partial['control_number'])
            ->assertJsonPath('body.billAmount', '900000')
            ->assertJsonPath('body.billCurrency', 'TZS')
            ->assertJsonPath('body.paymentOption', 'PARTIAL')
            ->assertJsonPath('body.minAmountToPay', '50000')
            ->assertJsonPath('body.paymentPlan', '2')
            ->assertJsonPath('body.billAccountCode', '23100000001')
            ->assertJsonPath('body.productCode', 'NGZP')
            ->assertJsonPath('body.customerName', 'Feza Nursery')
            ->assertJsonPath('body.billOwner.emailAddress', 'bursar@feza.tz')
            ->assertJsonPath('body.billStatus.Code', '103');

        $this->assertMatchesRegularExpression('#^\d{1,2}/\d{1,2}/\d{4} \d{1,2}:\d{2}:\d{2} (AM|PM)$#', $this->inquiry($partial['control_number'])->json('body.billExpirationDate'));

        $this->inquiry($exact['control_number'])->assertJsonPath('body.paymentOption', 'EXACT')->assertJsonPath('body.minAmountToPay', '30000');
        $this->inquiry($customer['account_number'])->assertJsonPath('body.paymentOption', 'PARTIAL')
            ->assertJsonPath('body.billAmount', '930000')->assertJsonPath('body.minAmountToPay', '1000');

        $this->inquiry('10010000000')->assertOk()->assertJsonPath('statusCode', 649)->assertJsonPath('body', []);
    }

    public function test_notification_pays_and_an_exact_replay_returns_the_same_receipt(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoiceWithControl($customer['id'], 10_000);

        $first = $this->notify($invoice['control_number'], '10000')->assertOk()
            ->assertJsonPath('statusCode', 600)
            ->assertJsonPath('body.transactionId', 'GWX9876738')
            ->assertJsonPath('body.paymentReference', $invoice['control_number']);
        $receipt = $first->json('body.receiptNumber');
        $this->assertSame($receipt, $first->json('body.barcode'));

        $payment = Payment::sole();
        $this->assertSame(['nmb', 'bank', '255658123693', 'William Frank', 'NMB123467587'],
            [$payment->provider, $payment->channel, $payment->payer_phone, $payment->payer_name, $payment->details['bank_reference']]);
        $this->getJson($this->url("invoices/{$invoice['id']}"))->assertJsonPath('data.status', 'paid');

        // NMB retries after a timeout: a 649 would reverse money we booked, so it gets 600 and the same receipt.
        $this->notify($invoice['control_number'], '10000')->assertJsonPath('statusCode', 600)->assertJsonPath('body.receiptNumber', $receipt);
        $this->assertSame(1, Payment::count());

        // Reusing the transaction or bank reference with other details is refused (NMB reverses it).
        $this->notify($invoice['control_number'], '9000')->assertJsonPath('statusCode', 649);
        $this->notify($invoice['control_number'], '10000', 'GWX-OTHER')->assertJsonPath('statusCode', 649);
        $this->assertSame(1, Payment::count());

        // Paid invoice: the inquiry says so.
        $this->inquiry($invoice['control_number'])->assertJsonPath('statusCode', 649)->assertJsonPath('message', 'Bill already paid');
        $this->assertLedgerBalanced();
    }

    public function test_exact_bill_cannot_be_paid_twice_and_unknown_references_are_refused(): void
    {
        $customer = $this->customer();
        $exact = $this->invoiceWithControl($customer['id'], 30_000, ['allow_partial' => false]);

        $this->notify($exact['control_number'], '30000', 'TX-A', 'BR-A')->assertJsonPath('statusCode', 600);
        $this->notify($exact['control_number'], '30000', 'TX-B', 'BR-B')->assertJsonPath('statusCode', 649)->assertJsonPath('message', 'Already Paid');
        $this->notify('10010000000', '5000', 'TX-C', 'BR-C')->assertJsonPath('statusCode', 649);
        $this->assertSame(1, Payment::count());
    }

    public function test_account_number_pays_oldest_first_and_nothing_owed_is_infinity(): void
    {
        $customer = $this->customer();
        $this->invoice($customer['id'], 20_000, ['due_date' => '2026-12-01']);
        $this->invoice($customer['id'], 20_000, ['due_date' => '2027-02-01']);

        $this->notify($customer['account_number'], '45000.00')->assertJsonPath('statusCode', 600);
        $this->assertSame(5_000, Customer::find($customer['id'])->credit_balance);

        $this->inquiry($customer['account_number'])->assertJsonPath('body.paymentOption', 'INFINITY')->assertJsonPath('body.billAmount', '0');
    }

    public function test_nmb_and_crdb_are_separate_integrations(): void
    {
        $customer = $this->customer();
        $this->notify($customer['account_number'], '5000', 'TX-1', 'BR-1')->assertJsonPath('statusCode', 600);

        // NMB money sits in its own clearing account, apart from CRDB and the mobile-money providers.
        $ledger = app(Ledger::class);
        $this->assertSame(5_000, $ledger->balance($ledger->providerClearing('nmb')));
        $this->assertSame(0, $ledger->balance($ledger->providerClearing('crdb')));

        // NMB's token opens nothing at CRDB, which needs CRDB's signature.
        $this->postJson('/api/v1/billers/crdb', ['paymentReference' => $customer['account_number'], 'institutionID' => 1],
            ['Authorization' => 'Bearer '.self::TOKEN])->assertJsonPath('status', '201');
    }
}
