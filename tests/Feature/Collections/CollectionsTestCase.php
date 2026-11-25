<?php

namespace Tests\Feature\Collections;

use App\Domain\Business\Enums\StaffRole;
use App\Domain\Business\Models\Business;
use App\Domain\Collections\Models\PaymentAttempt;
use App\Domain\Collections\Providers\MockProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Ledger\Models\LedgerLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class CollectionsTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nguzopay.payments.providers' => ['mock', 'snippe', 'azampay'], 'nguzopay.payments.default_provider' => 'mock']);
        $this->owner = $this->signIn();
        $this->business = $this->businessWith($this->owner, StaffRole::Owner, ['status' => 'active']);
    }

    protected function url(string $path = ''): string
    {
        return "/api/v1/businesses/{$this->business->id}/{$path}";
    }

    protected function customer(array $overrides = []): array
    {
        return $this->postJson($this->url('customers'), $overrides + [
            'name' => 'Asha Mussa', 'reference' => 'STD-'.random_int(1000, 9999), 'phone' => '0712345678',
        ])->assertCreated()->json('data');
    }

    protected function invoice(string $customerId, int $amount, array $overrides = []): array
    {
        return $this->postJson($this->url('invoices'), $overrides + [
            'customer_id' => $customerId,
            'lines' => [['description' => 'Term 1 fees', 'unit_amount' => $amount]],
        ])->assertCreated()->json('data');
    }

    protected function requestPayment(array $data): TestResponse
    {
        return $this->postJson($this->url('payment-intents'), $data + ['phone' => '0712345678']);
    }

    protected function simulate(string $intentId, string $outcome = 'success', ?int $amount = null): TestResponse
    {
        return $this->postJson($this->url("payment-intents/{$intentId}/simulate"), array_filter(['outcome' => $outcome, 'amount' => $amount]));
    }

    /** The mock network's webhook for the latest attempt of an intent. */
    protected function mockWebhook(string $intentId, string $outcome = 'success'): array
    {
        $attempt = PaymentAttempt::where('payment_intent_id', $intentId)->orderByDesc('id')->first();

        return app(MockProvider::class)->simulate($attempt->load('intent'), $outcome);
    }

    protected function assertLedgerBalanced(): void
    {
        $this->assertSame((int) LedgerLine::sum('debit'), (int) LedgerLine::sum('credit'), 'ledger debits must equal credits');
    }
}
