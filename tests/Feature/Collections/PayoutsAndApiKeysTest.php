<?php

namespace Tests\Feature\Collections;

use App\Domain\Billing\Models\Customer;
use App\Domain\Business\Enums\StaffRole;
use App\Domain\Collections\Models\Payment;
use App\Domain\Developers\Models\ApiKey;
use App\Domain\Developers\Services\ApiKeyService;
use App\Domain\Identity\Models\User;
use App\Domain\Ledger\Services\Ledger;
use App\Domain\Payouts\Models\Payout;
use App\Domain\Platform\Models\AuditLog;
use Laravel\Sanctum\Sanctum;

/** Withdrawals (D-027), API keys and the merchant API (D-028), and the Overview strip. */
class PayoutsAndApiKeysTest extends CollectionsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Money-moving actions need two-step verification.
        $this->owner->forceFill(['two_factor_enabled' => true, 'phone' => '255700000099'])->save();
    }

    /** Collect $amount by mobile money (mock), so NguzoPay owes it to the business. */
    private function collect(int $amount): void
    {
        $customer = $this->customer();
        $intent = $this->requestPayment(['customer_id' => $customer['id'], 'amount' => $amount])->json('data');
        $this->simulate($intent['id'])->assertOk();
    }

    private function withdraw(array $data = [])
    {
        return $this->postJson($this->url('payouts'), $data + [
            'amount' => 30_000, 'channel' => 'mobile_money', 'recipient_name' => 'Asha Mussa', 'recipient_account' => '0689000809', 'narration' => 'Weekly withdrawal',
        ]);
    }

    public function test_withdrawal_reserves_the_balance_and_finance_pays_it(): void
    {
        $this->collect(50_000);
        $this->getJson($this->url('payouts/balance'))->assertJsonPath('data.available', 50_000);

        $payout = $this->withdraw()->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.operator', 'airtel')
            ->assertJsonPath('data.recipient_account', '••••••0809')
            ->json('data');

        // Reserved straight away, so the same money can't be requested twice.
        $this->getJson($this->url('payouts/balance'))->assertJsonPath('data.available', 20_000)->assertJsonPath('data.reserved', 30_000);
        $this->withdraw(['amount' => 25_000])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->withdraw(['amount' => 500])->assertJsonValidationErrors('amount');

        // NGUZO finance sees the full account, pays, and records the transfer reference.
        $this->signIn(User::factory()->admin()->withTwoFactor()->create());
        $this->getJson('/api/v1/admin/payouts')->assertOk()->assertJsonPath('data.0.recipient_account', '255689000809');
        $this->postJson("/api/v1/admin/payouts/{$payout['id']}/processing")->assertJsonPath('data.status', 'processing');
        $this->postJson("/api/v1/admin/payouts/{$payout['id']}/complete", [])->assertJsonValidationErrors('external_reference');
        $this->postJson("/api/v1/admin/payouts/{$payout['id']}/complete", ['external_reference' => 'AIRTEL-TX-1'])->assertJsonPath('data.status', 'completed');
        $this->postJson("/api/v1/admin/payouts/{$payout['id']}/fail", ['reason' => 'x'])->assertJsonValidationErrors('status');

        // The ledger now owes the merchant only what is left.
        $ledger = app(Ledger::class);
        $this->assertSame(20_000, $ledger->balance($ledger->merchantPayable($this->business)));
        $this->assertLedgerBalanced();
        $this->assertSame(['payout.requested', 'payout.processing', 'payout.completed'], AuditLog::where('action', 'like', 'payout.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_failed_or_cancelled_withdrawals_release_the_balance(): void
    {
        $this->collect(50_000);
        $a = $this->withdraw()->json('data');
        $b = $this->withdraw(['amount' => 10_000, 'channel' => 'bank', 'bank_name' => 'CRDB', 'recipient_account' => '0150 1234 5678'])->assertCreated()->json('data');
        $this->getJson($this->url('payouts/balance'))->assertJsonPath('data.available', 10_000);

        $this->postJson($this->url("payouts/{$a['id']}/cancel"))->assertJsonPath('data.status', 'cancelled');
        $this->signIn(User::factory()->admin()->withTwoFactor()->create());
        $this->postJson("/api/v1/admin/payouts/{$b['id']}/fail", ['reason' => 'Account name mismatch'])->assertJsonPath('data.failure_reason', 'Account name mismatch');

        $this->signIn($this->owner);
        $this->getJson($this->url('payouts/balance'))->assertJsonPath('data.available', 50_000);
        $list = $this->getJson($this->url('payouts'))->assertOk();
        $this->assertSame(2, $list->json('meta.total'));
        $this->assertSame(50_000, $list->json('balance.available'));
    }

    public function test_withdrawals_need_permission_and_two_step(): void
    {
        $this->collect(50_000);

        $this->owner->forceFill(['two_factor_enabled' => false])->save();
        $this->withdraw()->assertForbidden();

        $viewer = User::factory()->withTwoFactor()->create();
        $this->business->members()->create(['user_id' => $viewer->id, 'role' => StaffRole::Viewer, 'status' => 'active']);
        Sanctum::actingAs($viewer);
        $this->withdraw()->assertForbidden();
        $this->getJson($this->url('payouts'))->assertOk();

        $this->assertSame(0, Payout::count());
    }

    public function test_api_keys_are_shown_once_scoped_and_revocable(): void
    {
        $this->collect(40_000);

        $res = $this->postJson($this->url('api-keys'), ['name' => 'School ERP', 'scopes' => ['balance:read', 'payments:read', 'payments:write'], 'expires_in_days' => 90])->assertCreated();
        $secret = $res->json('secret');
        $this->assertStringStartsWith('np_sk_', $secret);
        $this->assertSame(substr($secret, 0, 14), $res->json('data.prefix'));
        $this->assertNotSame($secret, ApiKey::sole()->token_hash);
        $this->getJson($this->url('api-keys'))->assertJsonMissing(['secret' => $secret])->assertJsonPath('data.0.status', 'active');

        // The key works on its own, without a session, for its scopes only.
        $this->app['auth']->forgetGuards();
        $auth = ['Authorization' => "Bearer {$secret}"];
        $this->getJson('/api/v1/merchant/balance', $auth)->assertOk()->assertJsonPath('data.available', 40_000);
        $this->getJson('/api/v1/merchant/payments', $auth)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/merchant/customers', $auth)->assertForbidden()->assertJsonPath('code', 'missing_scope');

        $customerId = Customer::value('id');
        $intent = $this->postJson('/api/v1/merchant/payment-requests', ['customer_id' => $customerId, 'amount' => 5_000, 'phone' => '0712345678'], $auth + ['Idempotency-Key' => 'erp-1'])
            ->assertCreated()->json('data');
        $this->postJson('/api/v1/merchant/payment-requests', ['customer_id' => $customerId, 'amount' => 5_000, 'phone' => '0712345678'], $auth + ['Idempotency-Key' => 'erp-1'])
            ->assertOk()->assertJsonPath('data.id', $intent['id']);
        $this->getJson("/api/v1/merchant/payment-requests/{$intent['id']}", $auth)->assertOk();
        $this->assertNotNull(ApiKey::sole()->last_used_at);

        $this->getJson('/api/v1/merchant/balance', ['Authorization' => 'Bearer np_sk_wrong'])->assertUnauthorized();

        // Revoked: refused at once.
        $this->signIn($this->owner);
        $this->postJson($this->url('api-keys/revoke-all'))->assertJsonPath('revoked', 1);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/merchant/balance', $auth)->assertUnauthorized()->assertJsonPath('code', 'invalid_api_key');

        $this->assertSame(['api_key.created', 'api_key.revoked_all'], AuditLog::where('action', 'like', 'api_key.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_a_key_only_sees_its_own_business(): void
    {
        $other = $this->businessWith(User::factory()->create(), StaffRole::Owner, ['status' => 'active']);
        [, $secret] = app(ApiKeyService::class)->create($other, 'Other', ['payments:read'], null, $this->owner);

        $this->collect(10_000);
        $paymentId = Payment::value('id');

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/merchant/payments/{$paymentId}", ['Authorization' => "Bearer {$secret}"])->assertNotFound();
        $this->getJson('/api/v1/merchant/payments', ['Authorization' => "Bearer {$secret}"])->assertJsonPath('meta.total', 0);
    }

    public function test_api_keys_need_the_permission(): void
    {
        $dev = User::factory()->create();
        $this->business->members()->create(['user_id' => $dev->id, 'role' => StaffRole::Support, 'status' => 'active']);
        Sanctum::actingAs($dev);
        $this->getJson($this->url('api-keys'))->assertForbidden();
    }

    public function test_overview_counts_money_in_money_out_and_outcomes(): void
    {
        $this->collect(50_000);
        $customer = $this->customer();
        $failed = $this->requestPayment(['customer_id' => $customer['id'], 'amount' => 3_000])->json('data');
        $this->simulate($failed['id'], 'failure')->assertOk();

        $payout = $this->withdraw(['amount' => 20_000])->json('data');
        $this->signIn(User::factory()->admin()->withTwoFactor()->create());
        $this->postJson("/api/v1/admin/payouts/{$payout['id']}/complete", ['external_reference' => 'TX-9'])->assertOk();

        $admin = $this->getJson('/api/v1/admin/overview?period=7d')->assertOk()->json('data');
        $this->assertSame(['amount' => 50_000, 'count' => 1], $admin['cards']['payments']);
        $this->assertSame(['amount' => 20_000, 'count' => 1], $admin['cards']['payouts']);
        $this->assertSame(50.0, (float) $admin['cards']['success_rate']['rate']);
        $this->assertSame(1, $admin['cards']['success_rate']['failed']);
        $this->assertCount(7, $admin['series']);
        $today = end($admin['series']);
        $this->assertSame([1, 1, 50_000, 20_000], [$today['completed'], $today['failed'], $today['payments'], $today['payouts']]);

        $this->signIn($this->owner);
        $this->getJson($this->url('overview?period=today'))->assertOk()->assertJsonPath('data.cards.payments.amount', 50_000)->assertJsonCount(1, 'data.series');
        $this->getJson($this->url('overview?period=all'))->assertOk();
        $this->getJson($this->url('overview?period=year'))->assertUnprocessable();
    }
}
