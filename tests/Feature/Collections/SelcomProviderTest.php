<?php

namespace Tests\Feature\Collections;

use App\Domain\Collections\Models\InboundEvent;
use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Models\PaymentAttempt;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Selcom Checkout, as zanur runs it in production: create order, wallet push, webhook confirmed by order-status. */
class SelcomProviderTest extends CollectionsTestCase
{
    /** @var list<Request> */
    private array $sent = [];

    /** What Selcom's order-status answers next: null = order unknown. */
    private ?array $order = null;

    private array $createResponse = ['resultcode' => '000', 'result' => 'SUCCESS', 'message' => 'Order created', 'reference' => 'SEL-REF', 'data' => [['payment_token' => '8000']]];

    private array $pushResponse = ['resultcode' => '111', 'result' => 'PENDING', 'message' => 'Request in progress. You will receive a callback shortly.', 'data' => []];

    private int $createStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'nguzopay.payments.providers' => ['mock', 'snippe', 'azampay', 'selcom'],
            'nguzopay.payments.selcom.vendor' => 'TILL000001',
            'nguzopay.payments.selcom.api_key' => 'sel-key',
            'nguzopay.payments.selcom.api_secret' => 'sel-secret',
            'nguzopay.payments.selcom.webhook_secret' => 'hook-secret',
            'nguzopay.payments.selcom.fallback_email' => 'payments@nguzo.test',
            'nguzopay.payments.webhook_base_url' => 'https://api.nguzopay.test',
        ]);

        Http::fake(function (Request $request) {
            $this->sent[] = $request;
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/v1/checkout/create-order-minimal' => Http::response($this->createResponse, $this->createStatus),
                '/v1/checkout/wallet-payment' => Http::response($this->pushResponse),
                '/v1/checkout/order-status' => Http::response($this->order
                    ? ['resultcode' => '000', 'result' => 'SUCCESS', 'message' => 'Order fetch successful', 'data' => [$this->order]]
                    : ['resultcode' => '404', 'result' => 'FAIL', 'message' => 'Order not found', 'data' => []]),
                '/v1/checkout/cancel-order' => Http::response(['resultcode' => '000', 'result' => 'SUCCESS', 'data' => []]),
            };
        });
    }

    private function intent(int $amount = 30_000): array
    {
        $customer = $this->customer(['email' => null]);

        return $this->requestPayment(['customer_id' => $customer['id'], 'amount' => $amount, 'provider' => 'selcom'])->json('data');
    }

    private function sentTo(string $path): array
    {
        return array_values(array_filter($this->sent, fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === $path));
    }

    private function completed(string $orderId, int $amount = 30_000, string $status = 'COMPLETED'): array
    {
        return ['order_id' => $orderId, 'creation_date' => '2026-11-25 10:00:00', 'amount' => (string) $amount, 'payment_status' => $status,
            'transid' => 'MP2611AB', 'channel' => 'MPESATZ', 'reference' => '856266164161', 'phone' => '255712345678'];
    }

    private function webhook(string $orderId, ?string $token = null, array $body = [])
    {
        $token ??= hash_hmac('sha256', 'selcom-order:'.$orderId, 'hook-secret');

        return $this->postJson('/api/v1/webhooks/selcom?token='.$token, $body + [
            'result' => 'SUCCESS', 'resultcode' => '000', 'order_id' => $orderId, 'transid' => 'MP2611AB',
            'reference' => '856266164161', 'channel' => 'MPESATZ', 'amount' => '30000', 'phone' => '255712345678', 'payment_status' => 'COMPLETED',
        ]);
    }

    public function test_order_and_push_are_signed_like_selcoms_client(): void
    {
        $this->business->forceFill(['email' => null])->save(); // no customer or business email: fallback
        $intent = $this->intent();
        $attempt = PaymentAttempt::sole();

        $this->assertSame('processing', $intent['status']);
        $this->assertSame($attempt->id, $attempt->provider_reference);

        [$create] = $this->sentTo('/v1/checkout/create-order-minimal');
        $this->assertSame('TILL000001', $create['vendor']);
        $this->assertSame($attempt->id, $create['order_id']);
        $this->assertSame(30_000, $create['amount']);
        $this->assertSame('255712345678', $create['buyer_phone']);
        $this->assertSame('payments@nguzo.test', $create['buyer_email']);
        $this->assertSame(1, $create['no_of_items']);
        $this->assertSame(
            'https://api.nguzopay.test/api/v1/webhooks/selcom?token='.hash_hmac('sha256', 'selcom-order:'.$attempt->id, 'hook-secret'),
            base64_decode($create['webhook']),
        );

        // HMAC-SHA256 over "timestamp=<ts>&field=value..." in Signed-Fields order.
        $this->assertSame('SELCOM '.base64_encode('sel-key'), $create->header('Authorization')[0]);
        $this->assertSame('HS256', $create->header('Digest-Method')[0]);
        $fields = explode(',', $create->header('Signed-Fields')[0]);
        $this->assertSame(array_keys($create->data()), $fields);
        $signed = 'timestamp='.$create->header('Timestamp')[0];
        foreach ($fields as $f) {
            $signed .= "&{$f}=".$create[$f];
        }
        $this->assertSame(base64_encode(hash_hmac('sha256', $signed, 'sel-secret', true)), $create->header('Digest')[0]);
        $this->assertStringEndsWith('+03:00', $create->header('Timestamp')[0]);

        [$push] = $this->sentTo('/v1/checkout/wallet-payment');
        $this->assertSame(['transid' => $attempt->id, 'order_id' => $attempt->id, 'msisdn' => '255712345678'], $push->data());
        $this->assertSame('transid,order_id,msisdn', $push->header('Signed-Fields')[0]);
    }

    public function test_webhook_is_confirmed_by_order_status_and_processed_once(): void
    {
        $intent = $this->intent();
        $attempt = PaymentAttempt::sole();
        $this->order = $this->completed($attempt->id);

        $this->webhook($attempt->id)->assertOk()->assertJsonPath('status', 'accepted');
        $this->webhook($attempt->id)->assertOk()->assertJsonPath('status', 'duplicate');

        $payment = Payment::sole();
        $this->assertSame([30_000, 'selcom', '255712345678'], [$payment->amount, $payment->provider, $payment->payer_phone]);
        $this->getJson($this->url("payment-intents/{$intent['id']}"))->assertJsonPath('data.status', 'succeeded');
        $this->assertCount(2, $this->sentTo('/v1/checkout/order-status'));

        // The reconciler finds the same outcome and adds nothing.
        $this->travel(2)->minutes();
        $this->artisan('nguzopay:payments:reconcile')->assertSuccessful();
        $this->assertSame(1, Payment::count());
        $this->assertLedgerBalanced();
    }

    public function test_webhook_without_our_token_is_rejected_without_asking_selcom(): void
    {
        $this->intent();
        $attempt = PaymentAttempt::sole();
        $this->order = $this->completed($attempt->id);

        $this->webhook($attempt->id, 'forged')->assertStatus(401);
        $this->postJson('/api/v1/webhooks/selcom', ['order_id' => $attempt->id, 'payment_status' => 'COMPLETED'])->assertStatus(401);

        $this->assertCount(0, $this->sentTo('/v1/checkout/order-status'));
        $this->assertSame(0, Payment::count());
        $this->assertSame(2, InboundEvent::where('status', 'rejected')->count());
    }

    public function test_webhook_body_is_never_trusted_over_order_status(): void
    {
        $intent = $this->intent();
        $attempt = PaymentAttempt::sole();
        $this->order = $this->completed($attempt->id, status: 'PENDING');

        $this->webhook($attempt->id)->assertOk();

        $this->assertSame(0, Payment::count());
        $this->getJson($this->url("payment-intents/{$intent['id']}"))->assertJsonPath('data.status', 'processing');
    }

    public function test_definite_push_failure_fails_the_intent_and_cancels_the_order(): void
    {
        $this->pushResponse = ['resultcode' => '403', 'result' => 'FAIL', 'message' => 'Invalid msisdn', 'data' => []];

        $intent = $this->intent();

        $this->assertSame('failed', $intent['status']);
        $this->assertCount(1, $this->sentTo('/v1/checkout/cancel-order'));
        $this->assertSame('DELETE', $this->sentTo('/v1/checkout/cancel-order')[0]->method());
    }

    public function test_ambiguous_create_is_replayed_then_settled_from_status(): void
    {
        $this->createStatus = 502;
        $this->createResponse = ['message' => 'Bad gateway'];

        $intent = $this->intent();
        $attempt = PaymentAttempt::sole();
        $this->assertSame(['processing', 'ambiguous', null], [$intent['status'], $attempt->error_kind, $attempt->provider_reference]);

        // Selcom did create the order before the gateway failed: the replay's create is refused,
        // order-status finds the order, and the push goes out for it.
        $this->createStatus = 200;
        $this->createResponse = ['resultcode' => '400', 'result' => 'FAIL', 'message' => 'Duplicate order id'];
        $this->order = $this->completed($attempt->id);

        $this->travel(2)->minutes();
        $this->artisan('nguzopay:payments:reconcile')->assertSuccessful();

        $this->assertSame($attempt->id, $attempt->fresh()->provider_reference);
        $this->assertCount(2, $this->sentTo('/v1/checkout/create-order-minimal'));
        $this->assertSame(1, Payment::count());
        $this->getJson($this->url("payment-intents/{$intent['id']}"))->assertJsonPath('data.status', 'succeeded');
        $this->assertLedgerBalanced();
    }

    public function test_status_with_another_amount_is_not_trusted_and_cancellation_fails_the_intent(): void
    {
        $intent = $this->intent();
        $attempt = PaymentAttempt::sole();

        $this->order = $this->completed($attempt->id, 29_999);
        $this->travel(2)->minutes();
        $this->artisan('nguzopay:payments:reconcile')->assertSuccessful();
        $this->assertSame(0, Payment::count());

        $this->order = $this->completed($attempt->id, status: 'USERCANCELLED');
        $this->artisan('nguzopay:payments:reconcile')->assertSuccessful();
        $this->assertSame(0, Payment::count());
        $this->getJson($this->url("payment-intents/{$intent['id']}"))->assertJsonPath('data.status', 'failed');
    }

    public function test_resend_prompt_uses_a_new_transid_for_the_same_order(): void
    {
        $intent = $this->intent();
        $attempt = PaymentAttempt::sole();

        $this->postJson($this->url("payment-intents/{$intent['id']}/resend"))->assertOk()->assertJsonPath('sent', true);

        $pushes = $this->sentTo('/v1/checkout/wallet-payment');
        $this->assertCount(2, $pushes);
        $this->assertSame($attempt->id, $pushes[1]['order_id']);
        $this->assertNotSame($pushes[0]['transid'], $pushes[1]['transid']);
    }
}
