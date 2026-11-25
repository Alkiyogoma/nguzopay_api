<?php

namespace Tests\Feature\Collections;

use App\Domain\Billing\Models\Customer;
use App\Domain\Collections\Models\InboundEvent;
use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Models\PaymentAttempt;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Behaviour ported from the DarSMS Snippe integration (darsms-backend, billing/providers). */
class SnippeRecoveryTest extends CollectionsTestCase
{
    private const SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'nguzopay.payments.snippe.api_key' => 'snp_test_key',
            'nguzopay.payments.snippe.webhook_secret' => self::SECRET,
        ]);
    }

    private function intent(): array
    {
        $customer = $this->customer();

        return $this->requestPayment(['customer_id' => $customer['id'], 'amount' => 50_000, 'provider' => 'snippe'])->json('data');
    }

    private function postWebhook(array $body, array $headers)
    {
        return $this->call('POST', '/api/v1/webhooks/snippe', [], [], [], $headers + ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    }

    private function signed(array $body, ?string $format = 'hex')
    {
        $raw = json_encode($body);
        $ts = (string) time();
        $mac = hash_hmac('sha256', "{$ts}.{$raw}", self::SECRET, true);
        $signature = match ($format) {
            'hex' => bin2hex($mac),
            'prefixed' => 'sha256='.bin2hex($mac),
            'base64' => base64_encode($mac),
        };

        return $this->postWebhook($body, ['HTTP_X_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_X_WEBHOOK_SIGNATURE' => $signature]);
    }

    private function event(string $type, array $data): array
    {
        return ['id' => 'evt_'.uniqid(), 'type' => $type, 'data' => $data + ['reference' => 'snp-ref-1']];
    }

    private function fakeCreated(): void
    {
        Http::fake(['api.snippe.sh/v1/payments' => Http::response(['data' => ['reference' => 'snp-ref-1', 'status' => 'pending']], 201)]);
    }

    public function test_cancelled_with_expired_code_is_a_final_expiry(): void
    {
        $this->fakeCreated();
        $intent = $this->intent();

        $this->signed($this->event('payment.cancelled', ['status' => 'cancelled', 'failure_code' => 'expired', 'failure_reason' => 'Payment expired.']))->assertOk();

        $this->getJson($this->url("payment-intents/{$intent['id']}"))
            ->assertJsonPath('data.status', 'expired')->assertJsonPath('data.failure_reason', 'Payment expired.');
    }

    public function test_status_only_payloads_and_spelling_variants_map_to_final_states(): void
    {
        $this->fakeCreated();
        $intent = $this->intent();

        // No known event type: fall back to data.status.
        $this->signed(['id' => 'evt_c', 'type' => 'payment.updated', 'data' => ['reference' => 'snp-ref-1', 'status' => 'canceled']])->assertOk();
        $this->getJson($this->url("payment-intents/{$intent['id']}"))->assertJsonPath('data.status', 'cancelled');
    }

    public function test_signature_header_formats_and_legacy_body_only_signature(): void
    {
        $this->fakeCreated();
        $this->intent();

        $this->signed($this->event('payment.updated', ['status' => 'pending']), 'prefixed')->assertOk();
        $this->signed($this->event('payment.updated', ['status' => 'pending']), 'base64')->assertOk();

        // Legacy callbacks carry no timestamp header and sign the raw body alone.
        $legacy = ['event' => 'payment.completed', 'reference' => 'snp-ref-1', 'status' => 'completed', 'amount' => ['value' => 50_000, 'currency' => 'TZS']];
        $this->postWebhook($legacy, ['HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', json_encode($legacy), self::SECRET)])->assertOk();
        $this->assertSame(1, Payment::count());

        config(['nguzopay.payments.snippe.accept_legacy_signature' => false]);
        $other = $legacy + ['id' => 'x'];
        $this->postWebhook($other, ['HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', json_encode($other), self::SECRET)])->assertStatus(401);
    }

    public function test_attempt_id_in_metadata_is_only_a_hint(): void
    {
        $this->fakeCreated();
        $this->intent();
        $attempt = PaymentAttempt::sole();

        // Points at our attempt but carries another payment's reference: not bound.
        $this->signed($this->event('payment.completed', [
            'reference' => 'snp-someone-else', 'status' => 'completed',
            'amount' => ['value' => 50_000, 'currency' => 'TZS'], 'metadata' => ['attempt_id' => $attempt->id],
        ]))->assertOk();

        $this->assertSame(0, Payment::count());
        $this->assertSame('unmatched', InboundEvent::sole()->status);
    }

    public function test_reversals_and_foreign_currency_need_review(): void
    {
        $this->fakeCreated();
        $this->intent();

        $this->signed($this->event('payment.reversed', ['status' => 'reversed']))->assertOk();
        $this->signed($this->event('payment.completed', ['status' => 'completed', 'amount' => ['value' => 50_000, 'currency' => 'USD']]))->assertOk();

        $this->assertSame(['needs_review', 'needs_review'], InboundEvent::pluck('status')->all());
        $this->assertSame(0, Payment::count());
    }

    public function test_ambiguous_send_is_replayed_with_the_same_key_then_settled_from_status(): void
    {
        $calls = [];
        Http::fake(function (Request $request) use (&$calls) {
            $calls[] = [$request->method(), $request->url(), $request->header('Idempotency-Key')[0] ?? null];

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/payments')) {
                return count($calls) === 1
                    ? Http::response(['status' => 'error', 'error_code' => 'PAY_001', 'message' => 'failed to initiate payment'], 500)
                    : Http::response(['data' => ['reference' => 'snp-ref-1', 'status' => 'pending']], 201); // Snippe's cached original
            }

            return Http::response(['data' => [
                'reference' => 'snp-ref-1', 'status' => 'completed',
                'amount' => ['value' => 50_000, 'currency' => 'TZS'],
                'settlement' => ['fees' => ['value' => 750, 'currency' => 'TZS']],
                'channel' => ['provider' => 'airtel'],
            ]]);
        });

        $intent = $this->intent();
        $attempt = PaymentAttempt::sole();
        $this->assertSame(['processing', 'ambiguous'], [$intent['status'], $attempt->error_kind]);

        $this->travel(2)->minutes();
        $this->artisan('nguzopay:payments:reconcile')->assertSuccessful();

        // Replay used the same key, then the status lookup settled the payment without a webhook.
        $this->assertSame([$attempt->id, $attempt->id], [$calls[0][2], $calls[1][2]]);
        $this->assertSame('GET', $calls[2][0]);
        $this->assertSame('snp-ref-1', $attempt->fresh()->provider_reference);

        $payment = Payment::sole();
        $this->assertSame([50_000, 750, false], [$payment->amount, $payment->provider_fee, $payment->provider_fee_estimated]);
        $this->getJson($this->url("payment-intents/{$intent['id']}"))->assertJsonPath('data.status', 'succeeded');

        // Running it again, or the late webhook, changes nothing.
        $this->artisan('nguzopay:payments:reconcile')->assertSuccessful();
        $this->assertSame(1, Payment::count());
        $this->assertLedgerBalanced();
    }

    public function test_status_that_does_not_match_the_saved_payment_is_not_trusted(): void
    {
        Http::fake([
            'api.snippe.sh/v1/payments' => Http::response(['data' => ['reference' => 'snp-ref-1']], 201),
            'api.snippe.sh/v1/payments/*' => Http::response(['data' => [
                'reference' => 'snp-ref-1', 'status' => 'completed', 'amount' => ['value' => 49_999, 'currency' => 'TZS'],
            ]]),
        ]);
        $this->intent();

        $this->travel(2)->minutes();
        $this->artisan('nguzopay:payments:reconcile')->assertSuccessful();
        $this->assertSame(0, Payment::count());
    }

    public function test_a_payment_snippe_still_holds_open_is_not_expired_by_age(): void
    {
        Http::fake([
            'api.snippe.sh/v1/payments' => Http::response(['data' => ['reference' => 'snp-ref-1']], 201),
            'api.snippe.sh/v1/payments/*' => Http::response(['data' => [
                'reference' => 'snp-ref-1', 'status' => 'pending', 'amount' => ['value' => 50_000, 'currency' => 'TZS'],
            ]]),
        ]);
        $intent = $this->intent();

        $this->travel(45)->minutes();
        $this->artisan('nguzopay:payments:reconcile')->assertSuccessful();
        // Still open at Snippe: expiring it would allow a retry and a double charge.
        $this->getJson($this->url("payment-intents/{$intent['id']}"))->assertJsonPath('data.status', 'processing');
        $this->postJson($this->url("payment-intents/{$intent['id']}/retry"))->assertUnprocessable();

        $this->travel(4)->hours();
        $this->artisan('nguzopay:payments:reconcile')->assertSuccessful();
        $this->getJson($this->url("payment-intents/{$intent['id']}"))->assertJsonPath('data.status', 'expired');
    }

    public function test_resend_prompt(): void
    {
        Http::fake([
            'api.snippe.sh/v1/payments' => Http::response(['data' => ['reference' => 'snp-ref-1']], 201),
            'api.snippe.sh/v1/payments/snp-ref-1/push' => Http::response(['data' => ['reference' => 'snp-ref-1', 'status' => 'pending'], 'message' => 'USSD push sent']),
        ]);
        $intent = $this->intent();

        $this->postJson($this->url("payment-intents/{$intent['id']}/resend"))->assertOk()->assertJsonPath('sent', true);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/payments/snp-ref-1/push') && $r['phone'] === '255712345678');
    }

    public function test_replay_sends_the_saved_request_unchanged_and_422_stays_ambiguous(): void
    {
        $bodies = [];
        Http::fake(function (Request $request) use (&$bodies) {
            $bodies[] = $request->body();

            return count($bodies) === 1
                ? Http::response(['message' => 'failed to initiate payment'], 503)
                : Http::response(['status' => 'error', 'code' => 422, 'message' => 'idempotency key already used with different request body'], 422);
        });
        $intent = $this->intent();

        // The customer's details change before the replay.
        Customer::query()->update(['email' => 'changed@example.tz', 'name' => 'Other Name']);
        $this->travel(2)->minutes();
        $this->artisan('nguzopay:payments:reconcile')->assertSuccessful();

        $this->assertSame($bodies[0], $bodies[1], 'replay must reuse the original body');
        $this->getJson($this->url("payment-intents/{$intent['id']}"))->assertJsonPath('data.status', 'processing');
        $this->assertSame('processing', PaymentAttempt::sole()->status->value);
    }
}
