<?php

namespace Tests\Feature\Collections;

use App\Domain\Billing\Models\Customer;
use App\Domain\Billing\Services\ControlNumberService;
use App\Domain\Collections\Models\BillerCall;
use App\Domain\Collections\Models\Payment;
use Illuminate\Testing\TestResponse;

class CrdbBillerTest extends CollectionsTestCase
{
    private const INSTITUTION = '89785560928600';

    private string $privateKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        config([
            'nguzopay.payments.crdb.institution_id' => self::INSTITUTION,
            'nguzopay.payments.crdb.public_key' => openssl_pkey_get_details($key)['key'],
            'nguzopay.payments.crdb.payment_type' => '20',
        ]);
    }

    /** CRDB's side: sign the fields in the guide's order, then add token and checksum. */
    private function crdb(string $signing, string $reference, string $jsonWithoutAuth, array $tamper = []): TestResponse
    {
        openssl_sign($signing, $sig, $this->privateKey, OPENSSL_ALGO_SHA256);
        $token = $tamper['token'] ?? base64_encode($sig);
        $checksum = $tamper['checksum'] ?? sha1($token.md5($reference));
        $body = rtrim($jsonWithoutAuth, '}').',"token":"'.$token.'","checksum":"'.$checksum.'"}';

        return $this->call('POST', '/api/v1/billers/crdb', [], [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.20.0.5'], $body);
    }

    private function verify(string $reference, array $tamper = [], string $institution = self::INSTITUTION): TestResponse
    {
        // institutionID is a JSON number in CRDB's sample.
        return $this->crdb($institution.$reference, $reference,
            '{"paymentReference":"'.$reference.'","institutionID":'.$institution.'}', $tamper);
    }

    private function pay(string $reference, string $amount, string $transactionRef = 'TX-1', string $amountType = 'FLEXIBLE'): TestResponse
    {
        $f = [
            'payerName' => 'Jonas Nkwabi', 'payerId' => '', 'currency' => 'TZS', 'amountType' => $amountType,
            'paymentDesc' => 'Payment for '.$reference, 'paymentType' => '20', 'transactionRef' => $transactionRef,
            'transactionChannel' => 'SIMAPP', 'payerMobile' => '255748000000',
        ];
        $signing = $f['payerName'].$f['payerId'].$amount.$f['currency'].$f['amountType'].$f['paymentDesc']
            .$f['paymentType'].$f['transactionRef'].$f['transactionChannel'].$f['payerMobile'].$reference.self::INSTITUTION;

        $json = '{"payerName":"Jonas Nkwabi","payerID":"","paymentReference":"'.$reference.'","amount":'.$amount
            .',"currency":"TZS","amountType":"'.$amountType.'","paymentDesc":"'.$f['paymentDesc'].'","paymentType":"20"'
            .',"transactionRef":"'.$transactionRef.'","transactionChannel":"SIMAPP","transactionDate":"2026-11-25 10:33:52"'
            .',"institutionID":"'.self::INSTITUTION.'","payerMobile":"255748000000"}';

        return $this->crdb($signing, $reference, $json);
    }

    public function test_control_numbers_are_numeric_with_a_check_digit(): void
    {
        $customer = $this->customer();
        $invoice = $this->getJson($this->url('invoices/'.$this->invoice($customer['id'], 10_000)['id']))->json('data');

        foreach ([$customer['account_number'], $invoice['control_number']] as $number) {
            $this->assertMatchesRegularExpression('/^\d{11}$/', $number);
            $this->assertStringStartsWith((string) $this->business->code, $number);
            $this->assertTrue(ControlNumberService::isValid($number));
        }
        $this->assertNotSame($customer['account_number'], $invoice['control_number']);

        // One mistyped digit fails the check digit, so it is not even looked up.
        $typo = substr($invoice['control_number'], 0, 5).((int) $invoice['control_number'][5] + 1) % 10 .substr($invoice['control_number'], 6);
        $this->verify($typo)->assertJsonPath('status', '204');
    }

    public function test_verification_of_invoices_and_customer_accounts(): void
    {
        $customer = $this->customer(['name' => 'Asha Mussa', 'reference' => 'STD-001']);
        $flexible = $this->getJson($this->url('invoices/'.$this->invoice($customer['id'], 300_000)['id']))->json('data');
        $fixed = $this->getJson($this->url('invoices/'.$this->invoice($customer['id'], 50_000, ['allow_partial' => false])['id']))->json('data');

        $this->verify($flexible['control_number'])->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.payerName', 'Asha Mussa')
            ->assertJsonPath('data.amount', 300_000)
            ->assertJsonPath('data.amountType', 'FLEXIBLE')
            ->assertJsonPath('data.currency', 'TZS')
            ->assertJsonPath('data.paymentType', '20')
            ->assertJsonPath('data.payerID', 'STD-001');

        $this->verify($fixed['control_number'])->assertJsonPath('data.amountType', 'FIXED')->assertJsonPath('data.amount', 50_000);

        // The account number shows everything outstanding.
        $this->verify($customer['account_number'])->assertJsonPath('data.amount', 350_000)->assertJsonPath('data.amountType', 'FLEXIBLE');

        // Unknown or voided references are invalid.
        $this->verify('10010000000')->assertJsonPath('status', '204');
        $this->postJson($this->url("invoices/{$flexible['id']}/void"), ['reason' => 'Wrong amount'])->assertOk();
        $this->verify($flexible['control_number'])->assertJsonPath('status', '204')->assertJsonPath('statusDesc', 'Invalid payment reference number');
    }

    public function test_authentication_failures(): void
    {
        $customer = $this->customer();
        $reference = $customer['account_number'];

        $this->verify($reference, ['checksum' => sha1('nope')])->assertJsonPath('status', '202');
        $this->verify($reference, ['token' => base64_encode(random_bytes(256))])->assertJsonPath('status', '201');
        $this->verify($reference, [], '11111111111111')->assertJsonPath('status', '201'); // another institution

        config(['nguzopay.payments.crdb.public_key' => null]);
        $this->verify($reference)->assertJsonPath('status', '201');
    }

    public function test_payment_posting_pays_the_invoice_and_duplicates_return_the_same_receipt(): void
    {
        config(['nguzopay.pricing.fee_bps' => 100]);
        $customer = $this->customer();
        $invoice = $this->getJson($this->url('invoices/'.$this->invoice($customer['id'], 80_000)['id']))->json('data');

        // The amount is signed exactly as CRDB wrote it (80000.00, not 80000).
        $receipt = $this->pay($invoice['control_number'], '80000.00')->assertOk()
            ->assertJsonPath('status', 200)->json('data.receipt');
        $this->assertMatchesRegularExpression('/^\d{16}$/', $receipt);

        $payment = Payment::sole();
        $this->assertSame(['crdb', 'TX-1', 'bank', 80_000, 800, '255748000000', 'Jonas Nkwabi'],
            [$payment->provider, $payment->provider_reference, $payment->channel, $payment->amount, $payment->platform_fee, $payment->payer_phone, $payment->payer_name]);
        $this->assertSame('SIMAPP', $payment->details['transaction_channel']);
        $this->getJson($this->url("invoices/{$invoice['id']}"))->assertJsonPath('data.status', 'paid');

        // CRDB resends the same credit advice: no second payment, same receipt.
        $this->pay($invoice['control_number'], '80000.00')
            ->assertJsonPath('status', '206')->assertJsonPath('data.receipt', $receipt);
        $this->assertSame(1, Payment::count());

        // Once paid, verification says so.
        $this->verify($invoice['control_number'])->assertJsonPath('status', '203');
        $this->assertLedgerBalanced();
    }

    public function test_fixed_reference_cannot_be_paid_twice(): void
    {
        $customer = $this->customer();
        $invoice = $this->getJson($this->url('invoices/'.$this->invoice($customer['id'], 50_000, ['allow_partial' => false])['id']))->json('data');

        $this->pay($invoice['control_number'], '50000', 'TX-A', 'FIXED')->assertJsonPath('status', 200);
        $this->pay($invoice['control_number'], '50000', 'TX-B', 'FIXED')->assertJsonPath('status', '207');
        $this->assertSame(1, Payment::count());
    }

    public function test_account_number_pays_oldest_invoices_first_then_credit(): void
    {
        $customer = $this->customer();
        $older = $this->invoice($customer['id'], 30_000, ['due_date' => '2026-12-01']);
        $newer = $this->invoice($customer['id'], 40_000, ['due_date' => '2027-01-15']);

        $this->pay($customer['account_number'], '100000')->assertJsonPath('status', 200);

        $this->getJson($this->url("invoices/{$older['id']}"))->assertJsonPath('data.status', 'paid');
        $this->getJson($this->url("invoices/{$newer['id']}"))->assertJsonPath('data.status', 'paid');
        $this->assertSame(30_000, Customer::find($customer['id'])->credit_balance);

        // Nothing owed now: the account accepts a payment ahead (FULL, amount 0).
        $this->verify($customer['account_number'])->assertJsonPath('data.amount', 0)->assertJsonPath('data.amountType', 'FULL');
    }

    public function test_only_crdbs_vpn_addresses_may_call(): void
    {
        config(['nguzopay.payments.crdb.allowed_ips' => ['10.20.0.9']]);
        $customer = $this->customer();

        $this->verify($customer['account_number'])->assertForbidden()
            ->assertExactJson(['status' => '201', 'statusDesc' => 'Access denied']);

        $call = BillerCall::sole();
        $this->assertSame(['crdb', 'inquiry', $customer['account_number'], '201', 403],
            [$call->biller, $call->kind, $call->payment_reference, $call->result_code, $call->http_status]);
    }
}
