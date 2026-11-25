<?php

namespace Tests\Feature\Collections;

use App\Domain\Collections\Models\InboundEvent;
use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Models\PaymentAttempt;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class SnippeProviderTest extends CollectionsTestCase
{
    private const SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'nguzopay.payments.snippe.api_key' => 'snp_test_key',
            'nguzopay.payments.snippe.webhook_secret' => self::SECRET,
            'nguzopay.payments.webhook_base_url' => 'https://api.nguzopay.test',
        ]);
    }

    private function intent(int $amount = 50_000): array
    {
        $customer = $this->customer(['name' => 'Juma Hassan Ali', 'email' => null]);

        return $this->requestPayment(['customer_id' => $customer['id'], 'amount' => $amount, 'provider' => 'snippe', 'phone' => '0754000111'])->json('data');
    }

    private function webhook(array $body, ?int $timestamp = null, ?string $signature = null)
    {
        $raw = json_encode($body);
        $timestamp ??= time();
        $signature ??= hash_hmac('sha256', "{$timestamp}.{$raw}", self::SECRET);

        return $this->call('POST', '/api/v1/webhooks/snippe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], $raw);
    }

    private function completed(string $attemptId, int $amount = 50_000, int $fee = 1_000): array
    {
        return [
            'id' => 'evt_'.$attemptId,
            'type' => 'payment.completed',
            'api_version' => '2026-01-25',
            'data' => [
                'reference' => 'snp-ref-1',
                'status' => 'completed',
                'amount' => ['value' => $amount, 'currency' => 'TZS'],
                'settlement' => ['gross' => ['value' => $amount], 'fees' => ['value' => $fee], 'net' => ['value' => $amount - $fee]],
                'channel' => ['type' => 'mobile_money', 'provider' => 'mpesa'],
                'customer' => ['phone' => '+255754000111'],
                'metadata' => ['attempt_id' => $attemptId],
            ],
        ];
    }

    public function test_push_request_matches_snippe_api(): void
    {
        Http::fake(['api.snippe.sh/*' => Http::response(['status' => 'success', 'code' => 201, 'data' => ['reference' => 'snp-ref-1', 'status' => 'pending']], 201)]);

        $intent = $this->intent();
        $attempt = PaymentAttempt::sole();

        $this->assertSame('processing', $intent['status']);
        $this->assertSame('snp-ref-1', $attempt->provider_reference);

        Http::assertSent(function (Request $request) use ($attempt) {
            return $request->url() === 'https://api.snippe.sh/v1/payments'
                && $request->header('Authorization')[0] === 'Bearer snp_test_key'
                && $request->header('Idempotency-Key')[0] === $attempt->id
                && strlen($attempt->id) <= 30
                && $request['payment_type'] === 'mobile'
                && $request['details'] === ['amount' => 50_000, 'currency' => 'TZS']
                && $request['phone_number'] === '255754000111'
                && $request['customer']['firstname'] === 'Juma'
                && $request['customer']['lastname'] === 'Hassan Ali'
                && $request['customer']['email'] === $this->business->email // no customer email: falls back to the business
                && $request['webhook_url'] === 'https://api.nguzopay.test/api/v1/webhooks/snippe'
                && $request['metadata']['attempt_id'] === $attempt->id;
        });
    }

    public function test_validation_error_is_definite_and_server_error_is_ambiguous(): void
    {
        Http::fakeSequence('api.snippe.sh/*')
            ->push(['status' => 'error', 'code' => 400, 'error_code' => 'validation_error', 'message' => 'phone_number must be a valid phone number'], 400)
            ->push(['status' => 'error', 'code' => 500, 'error_code' => 'PAY_001', 'message' => 'failed to initiate payment'], 500);

        $this->assertSame('failed', $this->intent()['status']);
        $this->assertStringContainsString('phone_number must be a valid', PaymentAttempt::orderBy('id')->first()->message);

        $this->assertSame('processing', $this->intent()['status']);
        $this->assertSame('ambiguous', PaymentAttempt::orderByDesc('id')->first()->error_kind);
    }

    public function test_signed_webhook_records_payment_with_snippes_real_fee(): void
    {
        Http::fake(['api.snippe.sh/*' => Http::response(['data' => ['reference' => 'snp-ref-1']], 201)]);
        $intent = $this->intent();
        $attempt = PaymentAttempt::sole();

        $this->webhook($this->completed($attempt->id))->assertOk()->assertJsonPath('status', 'accepted');

        $payment = Payment::sole();
        $this->assertSame([50_000, 1_000, false, 'mpesa'], [$payment->amount, $payment->provider_fee, $payment->provider_fee_estimated, $payment->operator->value]);
        $this->assertSame(49_000, $payment->net_amount);
        $this->getJson($this->url("payment-intents/{$intent['id']}"))->assertJsonPath('data.status', 'succeeded');
        $this->assertLedgerBalanced();
    }

    public function test_bad_signature_stale_timestamp_and_missing_secret_are_refused(): void
    {
        Http::fake(['api.snippe.sh/*' => Http::response(['data' => ['reference' => 'snp-ref-1']], 201)]);
        $this->intent();
        $body = $this->completed(PaymentAttempt::sole()->id);

        $this->webhook($body, signature: str_repeat('a', 64))->assertStatus(401);
        $this->webhook($body, timestamp: time() - 600)->assertStatus(401);

        config(['nguzopay.payments.snippe.webhook_secret' => null]);
        $this->webhook($body)->assertStatus(401);

        $this->assertSame(0, Payment::count());
        $this->assertSame(3, InboundEvent::where('status', 'rejected')->whereNull('event_key')->count());
    }

    public function test_failed_event_marks_the_request_failed(): void
    {
        Http::fake(['api.snippe.sh/*' => Http::response(['data' => ['reference' => 'snp-ref-1']], 201)]);
        $intent = $this->intent();

        $this->webhook([
            'id' => 'evt_fail', 'type' => 'payment.failed',
            'data' => ['reference' => 'snp-ref-1', 'status' => 'failed', 'failure_reason' => 'Transaction declined by user', 'metadata' => []],
        ])->assertOk();

        // Matched by Snippe's reference even without our metadata.
        $this->getJson($this->url("payment-intents/{$intent['id']}"))
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_reason', 'Transaction declined by user');
    }
}
