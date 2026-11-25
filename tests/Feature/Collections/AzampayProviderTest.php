<?php

namespace Tests\Feature\Collections;

use App\Domain\Collections\Models\InboundEvent;
use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Models\PaymentAttempt;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class AzampayProviderTest extends CollectionsTestCase
{
    private string $privateKey = '';

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $this->publicKey = openssl_pkey_get_details($key)['key'];

        config([
            'nguzopay.payments.azampay.app_name' => 'nguzopay',
            'nguzopay.payments.azampay.client_id' => 'client-1',
            'nguzopay.payments.azampay.client_secret' => 'secret-1',
            'nguzopay.payments.azampay.api_key' => 'az-api-key',
            'nguzopay.payments.azampay.public_key' => $this->publicKey,
            'nguzopay.payments.azampay.fee_bps' => 150,
        ]);
    }

    private function fakeAzampay(): void
    {
        Http::fake([
            'authenticator-sandbox.azampay.co.tz/*' => Http::response(['data' => ['accessToken' => 'jwt-1', 'expire' => '2026-12-01T00:00:00Z'], 'success' => true, 'statusCode' => 200]),
            'sandbox.azampay.co.tz/azampay/mno/checkout' => Http::response(['transactionId' => 'az-tx-1', 'message' => 'Request in progress', 'success' => true]),
        ]);
    }

    private function intent(string $phone = '0712345678', array $extra = []): array
    {
        $customer = $this->customer();

        return $this->requestPayment($extra + ['customer_id' => $customer['id'], 'amount' => 25_000, 'provider' => 'azampay', 'phone' => $phone])->json('data');
    }

    private function sign(string $data): string
    {
        openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    private function azampayCallback(string $attemptId, array $overrides = [], ?string $signedData = null)
    {
        $body = $overrides + [
            'message' => 'Transaction completed successfully',
            'user' => 'azampay-user',
            'password' => 'azampay-password',
            'clientId' => 'client-1',
            'transactionstatus' => 'success',
            'operator' => 'Tigo',
            'reference' => 'AZ-REF-1',
            'externalreference' => 'AZ-EXT-1',
            'utilityref' => $attemptId,
            'amount' => '25000',
            'transid' => 'TXN-1',
            'msisdn' => '255712345678',
            'mnoreference' => 'MNO-1',
        ];
        if (! array_key_exists('signature', $overrides)) {
            $body['signature'] = $this->sign($signedData ?? $body['utilityref'].$body['externalreference'].$body['transactionstatus'].$body['operator']);
        }

        return $this->postJson('/api/v1/webhooks/azampay', $body);
    }

    public function test_checkout_request_and_token_reuse(): void
    {
        $this->fakeAzampay();

        $first = $this->intent();
        $this->intent('0754000111');

        $this->assertSame('processing', $first['status']);
        $this->assertSame('az-tx-1', PaymentAttempt::orderBy('id')->first()->provider_reference);

        Http::assertSentCount(3); // one token, two checkouts
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/AppRegistration/GenerateToken')
            && $r['appName'] === 'nguzopay' && $r['clientId'] === 'client-1' && $r['clientSecret'] === 'secret-1');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/azampay/mno/checkout')
            && $r->header('Authorization')[0] === 'Bearer jwt-1'
            && $r->header('X-API-Key')[0] === 'az-api-key'
            && $r['accountNumber'] === '255712345678'
            && $r['amount'] === '25000'
            && $r['currency'] === 'TZS'
            && $r['provider'] === 'Tigo'
            && $r['externalId'] === PaymentAttempt::orderBy('id')->first()->id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/azampay/mno/checkout') && $r['provider'] === 'Mpesa');
    }

    public function test_unknown_network_must_be_chosen(): void
    {
        $this->fakeAzampay();
        $customer = $this->customer();

        $this->requestPayment(['customer_id' => $customer['id'], 'amount' => 25_000, 'provider' => 'azampay', 'phone' => '0731234567'])
            ->assertUnprocessable()->assertJsonValidationErrors('operator');
        $this->requestPayment(['customer_id' => $customer['id'], 'amount' => 25_000, 'provider' => 'azampay', 'phone' => '0731234567', 'operator' => 'airtel'])
            ->assertCreated();
    }

    public function test_expired_token_is_refreshed_once(): void
    {
        Http::fake([
            'authenticator-sandbox.azampay.co.tz/*' => Http::sequence()
                ->push(['data' => ['accessToken' => 'old'], 'success' => true])
                ->push(['data' => ['accessToken' => 'new'], 'success' => true]),
            'sandbox.azampay.co.tz/azampay/mno/checkout' => Http::sequence()
                ->push(['message' => 'Please Provide Valid Authorization'], 401)
                ->push(['transactionId' => 'az-tx-2', 'success' => true]),
        ]);

        $this->assertSame('processing', $this->intent()['status']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout') && $r->header('Authorization')[0] === 'Bearer new');
    }

    public function test_bad_credentials_and_declined_push_are_definite_failures(): void
    {
        Http::fake(['authenticator-sandbox.azampay.co.tz/*' => Http::response(['message' => 'Provided detail is not valid', 'success' => false, 'statusCode' => 423], 423)]);
        $this->assertSame('failed', $this->intent()['status']);

        Http::fake([
            'authenticator-sandbox.azampay.co.tz/*' => Http::response(['data' => ['accessToken' => 'jwt-1'], 'success' => true]),
            'sandbox.azampay.co.tz/azampay/mno/checkout' => Http::response(['message' => 'Insufficient balance', 'success' => false]),
        ]);
        cache()->flush();
        $this->assertSame('failed', $this->intent()['status']);
    }

    public function test_signed_callback_records_payment_with_estimated_fee(): void
    {
        $this->fakeAzampay();
        $intent = $this->intent();

        $this->azampayCallback(PaymentAttempt::sole()->id)->assertOk()->assertJsonPath('status', 'accepted');

        $payment = Payment::sole();
        $this->assertSame([25_000, 375, true], [$payment->amount, $payment->provider_fee, $payment->provider_fee_estimated]); // 1.5% estimate
        $this->assertSame('tigo', $payment->operator->value);
        $this->getJson($this->url("payment-intents/{$intent['id']}"))->assertJsonPath('data.status', 'succeeded');

        // Azampay's credentials in the callback are never stored.
        $this->assertArrayNotHasKey('password', InboundEvent::sole()->payload);
        $this->assertLedgerBalanced();
    }

    public function test_two_field_signature_variant_is_accepted(): void
    {
        $this->fakeAzampay();
        $this->intent();
        $attemptId = PaymentAttempt::sole()->id;

        $this->azampayCallback($attemptId, [], signedData: $attemptId.'AZ-EXT-1')->assertOk();
        $this->assertSame(1, Payment::count());
    }

    public function test_bad_or_missing_signature_is_refused(): void
    {
        $this->fakeAzampay();
        $this->intent();
        $attemptId = PaymentAttempt::sole()->id;

        $this->azampayCallback($attemptId, ['amount' => '1'], signedData: 'something else')->assertStatus(401);
        $this->azampayCallback($attemptId, ['signature' => null])->assertStatus(401);
        $this->assertSame(0, Payment::count());
    }

    public function test_public_key_is_fetched_when_not_pinned(): void
    {
        config(['nguzopay.payments.azampay.public_key' => null]);
        Http::fake([
            'authenticator-sandbox.azampay.co.tz/*' => Http::response(['data' => ['accessToken' => 'jwt-1'], 'success' => true]),
            'sandbox.azampay.co.tz/azampay/mno/checkout' => Http::response(['transactionId' => 'az-tx-1', 'success' => true]),
            'sandbox.azampay.co.tz/azampay/v1/public-key*' => Http::response(['success' => true, 'format' => 'Pem', 'publicKey' => $this->publicKey]),
        ]);
        $this->intent();

        $this->azampayCallback(PaymentAttempt::sole()->id)->assertOk();
        $this->assertSame(1, Payment::count());
    }
}
