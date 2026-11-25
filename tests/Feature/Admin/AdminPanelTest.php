<?php

namespace Tests\Feature\Admin;

use App\Domain\Business\Enums\StaffRole;
use App\Domain\Collections\Models\BillerCall;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\AuditLog;
use Tests\Feature\Collections\CollectionsTestCase;

/** Back-office: businesses with performance, transactions, analytics and NGUZO staff. */
class AdminPanelTest extends CollectionsTestCase
{
    private User $admin;

    /** Two businesses with money: ours (2 × 20,000 by mobile money) and another (1 × 5,000). */
    private function seedPayments(): void
    {
        config(['nguzopay.pricing.fee_bps' => 100]); // 1 %

        $customer = $this->customer();
        foreach ([20_000, 20_000] as $amount) {
            $intent = $this->requestPayment(['customer_id' => $customer['id'], 'amount' => $amount])->json('data');
            $this->simulate($intent['id'])->assertOk();
        }

        $otherOwner = User::factory()->create();
        $other = $this->businessWith($otherOwner, StaffRole::Owner, ['status' => 'active', 'trading_name' => 'Other Academy']);
        $this->signIn($otherOwner);
        $c = $this->postJson("/api/v1/businesses/{$other->id}/customers", ['name' => 'Juma', 'reference' => 'J-1', 'phone' => '0754000111'])->json('data');
        $intent = $this->postJson("/api/v1/businesses/{$other->id}/payment-intents", ['customer_id' => $c['id'], 'amount' => 5_000, 'phone' => '0754000111'])->json('data');
        $this->postJson("/api/v1/businesses/{$other->id}/payment-intents/{$intent['id']}/simulate", ['outcome' => 'success'])->assertOk();

        $this->admin = $this->signIn(User::factory()->admin()->withTwoFactor()->create());
    }

    public function test_merchants_cannot_reach_any_new_admin_page(): void
    {
        foreach (['merchants', 'payments', 'payment-intents', 'settlements', 'bank-calls', 'analytics', 'staff', 'reports/payments/export'] as $path) {
            $this->getJson("/api/v1/admin/{$path}")->assertForbidden();
        }
    }

    public function test_business_list_shows_performance_and_sorts_by_volume(): void
    {
        $this->seedPayments();

        $res = $this->getJson('/api/v1/admin/merchants')->assertOk();
        $this->assertSame([$this->business->id, 40_000, 2, 400], [
            $res->json('data.0.id'), $res->json('data.0.collected'), $res->json('data.0.payments_count'), $res->json('data.0.revenue'),
        ]);
        $this->assertSame(5_000, $res->json('data.1.collected'));
        $this->assertSame(45_000, collect($res->json('summary'))->firstWhere('key', 'collected')['value']);

        // The date range bounds performance but never hides a business.
        $res = $this->getJson('/api/v1/admin/merchants?from=2020-01-01&to=2020-01-31')->assertOk();
        $this->assertSame(2, $res->json('meta.total'));
        $this->assertSame(0, $res->json('data.0.collected'));

        $this->getJson('/api/v1/admin/merchants?q=Other')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.display_name', 'Other Academy');
    }

    public function test_platform_payments_filters_and_export(): void
    {
        $this->seedPayments();

        $all = $this->getJson('/api/v1/admin/payments')->assertOk();
        $this->assertSame(3, $all->json('meta.total'));
        $this->assertNotNull($all->json('data.0.business.code'));
        $this->assertSame(45_000, collect($all->json('summary'))->firstWhere('key', 'volume')['value']);
        $this->assertSame(450, collect($all->json('summary'))->firstWhere('key', 'revenue')['value']);

        $this->getJson("/api/v1/admin/payments?business_id={$this->business->id}")->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/admin/payment-intents?status=succeeded')->assertJsonPath('meta.total', 3);

        $print = $this->getJson('/api/v1/admin/reports/payments/export?format=json')->assertOk();
        $this->assertSame(['All payments', 'NguzoPay back-office', null, 3], [$print->json('data.title'), $print->json('data.business'), $print->json('data.merchant_code'), $print->json('data.total')]);
        $this->assertSame('business', $print->json('data.columns.0.key'));

        $this->get('/api/v1/admin/reports/businesses/export')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->getJson('/api/v1/admin/reports/nope/export')->assertNotFound();
        $this->assertSame(2, AuditLog::where('action', 'admin.report.exported')->count());
    }

    public function test_bank_calls_log_is_browsable(): void
    {
        $this->admin = $this->signIn(User::factory()->admin()->create());
        BillerCall::create(['biller' => 'nmb', 'endpoint' => 'api/v1/billers/nmb/inquiry', 'kind' => 'inquiry', 'payment_reference' => '12345678901', 'http_status' => 200, 'result_code' => '600', 'duration_ms' => 40]);
        BillerCall::create(['biller' => 'crdb', 'endpoint' => 'api/v1/billers/crdb', 'kind' => 'payment', 'transaction_id' => 'CRDB-1', 'http_status' => 403, 'result_code' => '201', 'duration_ms' => 4]);

        $res = $this->getJson('/api/v1/admin/bank-calls')->assertOk();
        $this->assertSame(2, $res->json('meta.total'));
        $this->assertSame([false, true], [$res->json('data.0.accepted'), $res->json('data.1.accepted')]);
        $this->assertSame(1, collect($res->json('summary'))->firstWhere('key', 'refused')['value']);

        $this->getJson('/api/v1/admin/bank-calls?outcome=refused')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.bank', 'crdb');
        $this->getJson('/api/v1/admin/bank-calls?q=12345')->assertJsonPath('meta.total', 1);
    }

    public function test_analytics_and_business_performance(): void
    {
        $this->seedPayments();

        $a = $this->getJson('/api/v1/admin/analytics')->assertOk()->json('data');
        $card = fn ($key) => collect($a['cards'])->firstWhere('key', $key)['value'];
        $this->assertSame([45_000, 3, 450, 2], [$card('volume'), $card('payments'), $card('revenue'), $card('active_businesses')]);
        $this->assertSame('day', $a['period']['interval']);
        $this->assertCount(30, $a['series']);
        $this->assertSame(45_000, array_sum(array_column($a['series'], 'volume')));
        $this->assertSame('mock', $a['providers'][0]['provider']);
        $this->assertSame(100.0, (float) $a['providers'][0]['success_rate']);
        $this->assertSame($this->business->id, $a['top_businesses'][0]['id']);

        $p = $this->getJson("/api/v1/admin/businesses/{$this->business->id}/performance")->assertOk();
        $this->assertSame(40_000, collect($p->json('data.cards'))->firstWhere('key', 'volume')['value']);
        $this->assertSame(1, $p->json('data.position.customers'));
        $this->assertSame(100, $p->json('pricing.effective_fee_bps'));

        // A year-long window is grouped by month.
        $this->assertSame('month', $this->getJson('/api/v1/admin/analytics?from=2026-01-01&to=2026-12-31')->json('data.period.interval'));
    }

    public function test_pricing_override_needs_two_step_and_a_reason_and_is_audited(): void
    {
        $this->signIn(User::factory()->admin()->create()); // no 2FA
        $this->putJson("/api/v1/admin/businesses/{$this->business->id}/pricing", ['fee_bps' => 50, 'reason' => 'Pilot'])->assertForbidden();

        $this->signIn(User::factory()->admin()->withTwoFactor()->create());
        $this->putJson("/api/v1/admin/businesses/{$this->business->id}/pricing", ['fee_bps' => 50])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->putJson("/api/v1/admin/businesses/{$this->business->id}/pricing", ['fee_bps' => 50, 'fee_fixed' => 200, 'reason' => 'Pilot school'])
            ->assertOk()->assertJsonPath('pricing.effective_fee_bps', 50)->assertJsonPath('pricing.fee_fixed', 200);

        $log = AuditLog::where('action', 'business.pricing_changed')->sole();
        $this->assertSame([null, 50, 'Pilot school'], [$log->properties['before']['fee_bps'], $log->properties['after']['fee_bps'], $log->properties['reason']]);

        // Back to the platform default.
        $this->putJson("/api/v1/admin/businesses/{$this->business->id}/pricing", ['fee_bps' => null, 'fee_fixed' => null, 'reason' => 'Pilot over'])
            ->assertJsonPath('pricing.fee_bps', null);
    }

    public function test_staff_access_is_granted_and_removed_safely(): void
    {
        $me = $this->signIn(User::factory()->admin()->withTwoFactor()->create());
        $newcomer = User::factory()->create(['email' => 'neema@nguzo.test']);

        $this->postJson('/api/v1/admin/staff', ['email' => 'nobody@nguzo.test', 'reason' => 'Ops'])->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/admin/staff', ['email' => 'Neema@nguzo.test', 'reason' => 'Joins ops'])->assertCreated();
        $this->assertTrue($newcomer->fresh()->is_platform_admin);
        $this->postJson('/api/v1/admin/staff', ['email' => 'neema@nguzo.test', 'reason' => 'Again'])->assertJsonValidationErrors('email');

        $this->getJson('/api/v1/admin/staff')->assertOk()->assertJsonCount(2, 'data');

        $this->deleteJson("/api/v1/admin/staff/{$me->id}", ['reason' => 'x'])->assertJsonValidationErrors('user');
        $this->deleteJson("/api/v1/admin/staff/{$newcomer->id}", ['reason' => 'Left NGUZO'])->assertOk();
        $this->assertFalse($newcomer->fresh()->is_platform_admin);

        $this->assertSame(['platform_admin.granted', 'platform_admin.revoked'],
            AuditLog::where('action', 'like', 'platform_admin.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_the_last_admin_cannot_be_removed(): void
    {
        // $me lost access in another session a moment ago (stale in-memory flag); $other is now the only admin.
        $me = User::factory()->withTwoFactor()->create();
        $other = User::factory()->admin()->create();
        $this->actingAs($me->forceFill(['is_platform_admin' => true]), 'sanctum');

        $this->deleteJson("/api/v1/admin/staff/{$other->id}", ['reason' => 'x'])->assertJsonValidationErrors('user');
        $this->assertTrue($other->fresh()->is_platform_admin);
    }
}
