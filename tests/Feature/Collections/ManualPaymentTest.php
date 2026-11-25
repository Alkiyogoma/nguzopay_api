<?php

namespace Tests\Feature\Collections;

use App\Domain\Billing\Models\Customer;
use App\Domain\Business\Enums\StaffRole;
use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Models\Settlement;
use App\Domain\Identity\Models\User;
use App\Domain\Ledger\Models\LedgerLine;
use Laravel\Sanctum\Sanctum;

class ManualPaymentTest extends CollectionsTestCase
{
    private function record(array $data)
    {
        return $this->postJson($this->url('payments'), $data + ['method' => 'cash']);
    }

    public function test_cash_payment_pays_the_invoice_but_stays_out_of_nguzopays_books(): void
    {
        config(['nguzopay.pricing.fee_bps' => 100]);
        $customer = $this->customer();
        $invoice = $this->invoice($customer['id'], 100_000, ['installments' => [['amount' => 60_000], ['amount' => 40_000]]]);

        $payment = $this->record(['invoice_id' => $invoice['id'], 'amount' => 70_000, 'reference' => 'Receipt book 0042', 'note' => 'Paid at the office'])
            ->assertCreated()
            ->assertJsonPath('data.manual', true)
            ->assertJsonPath('data.method_label', 'Cash')
            ->assertJsonPath('data.platform_fee', 0)
            ->assertJsonPath('data.net_amount', 70_000)
            ->assertJsonPath('data.details.reference', 'Receipt book 0042')
            ->json('data');
        $this->assertMatchesRegularExpression('/^\d{16}$/', $payment['receipt_number']);

        $this->getJson($this->url("invoices/{$invoice['id']}"))
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.installments.0.status', 'paid')
            ->assertJsonPath('data.installments.1.amount_paid', 10_000);

        // Never in NguzoPay's ledger, balance or settlement sweep.
        $this->assertSame(0, LedgerLine::count());
        $this->getJson($this->url('balance'))->assertJsonPath('data.unsettled_net', 0);
        $this->artisan('nguzopay:settlements:close')->assertSuccessful();
        $this->assertSame(0, Settlement::count());

        // But it counts as money received.
        $this->assertSame(70_000, collect($this->getJson($this->url('payments'))->json('summary'))->firstWhere('key', 'received')['value']);
        $this->getJson($this->url('payments?method=manual'))->assertJsonCount(1, 'data');
        $this->getJson($this->url('payments?method=mobile_money'))->assertJsonCount(0, 'data');
    }

    public function test_customer_payment_pays_oldest_invoices_then_credit_and_dates_are_checked(): void
    {
        $customer = $this->customer();
        $older = $this->invoice($customer['id'], 30_000, ['due_date' => '2026-12-01']);
        $this->invoice($customer['id'], 20_000, ['due_date' => '2027-01-01']);

        $this->record(['customer_id' => $customer['id'], 'amount' => 60_000, 'method' => 'bank_deposit', 'paid_on' => '2026-11-20'])->assertCreated();

        $this->getJson($this->url("invoices/{$older['id']}"))->assertJsonPath('data.status', 'paid');
        $this->assertSame(10_000, Customer::find($customer['id'])->credit_balance);
        $this->assertSame('2026-11-20 09:00:00', Payment::sole()->paid_at->format('Y-m-d H:i:s')); // noon EAT

        $this->record(['customer_id' => $customer['id'], 'amount' => 1_000, 'paid_on' => now()->addDays(3)->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('paid_on');
        $this->record(['amount' => 1_000])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->record(['customer_id' => $customer['id'], 'amount' => 1_000, 'method' => 'gold'])->assertUnprocessable()->assertJsonValidationErrors('method');
    }

    public function test_void_undoes_the_allocation_and_credit(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer['id'], 50_000, ['installments' => [['amount' => 25_000], ['amount' => 25_000]]]);
        $payment = $this->record(['invoice_id' => $invoice['id'], 'amount' => 60_000])->json('data');

        $this->getJson($this->url("invoices/{$invoice['id']}"))->assertJsonPath('data.status', 'paid');
        $this->assertSame(10_000, Customer::find($customer['id'])->credit_balance);

        $this->postJson($this->url("payments/{$payment['id']}/void"), ['reason' => 'Entered twice'])->assertOk()
            ->assertJsonPath('data.status', 'voided')
            ->assertJsonPath('data.details.void_reason', 'Entered twice');

        $this->getJson($this->url("invoices/{$invoice['id']}"))
            ->assertJsonPath('data.status', 'open')->assertJsonPath('data.amount_paid', 0)
            ->assertJsonPath('data.installments.0.amount_paid', 0)->assertJsonPath('data.allocations', []);
        $this->assertSame(0, Customer::find($customer['id'])->credit_balance);

        // Voided entries stay listed but are not counted; voiding twice is refused.
        $this->assertSame(0, collect($this->getJson($this->url('payments'))->json('summary'))->firstWhere('key', 'received')['value']);
        $this->postJson($this->url("payments/{$payment['id']}/void"), ['reason' => 'again'])->assertUnprocessable();
    }

    public function test_void_is_refused_when_the_credit_was_already_used_or_money_came_through_nguzopay(): void
    {
        $customer = $this->customer();
        $first = $this->invoice($customer['id'], 10_000);
        $payment = $this->record(['invoice_id' => $first['id'], 'amount' => 30_000])->json('data'); // 20,000 credit
        $this->invoice($customer['id'], 20_000); // credit auto-applies here

        $this->postJson($this->url("payments/{$payment['id']}/void"), ['reason' => 'Mistake'])->assertUnprocessable()->assertJsonValidationErrors('payment');

        $intent = $this->requestPayment(['customer_id' => $customer['id'], 'amount' => 5_000])->json('data');
        $this->simulate($intent['id']);
        $mobile = Payment::where('provider', 'mock')->sole();
        $this->postJson($this->url("payments/{$mobile->id}/void"), ['reason' => 'x'])->assertUnprocessable();
    }

    public function test_only_billing_managers_record_or_void(): void
    {
        $customer = $this->customer();
        $support = User::factory()->create();
        $this->business->members()->create(['user_id' => $support->id, 'role' => StaffRole::Support]);
        Sanctum::actingAs($support);

        $this->record(['customer_id' => $customer['id'], 'amount' => 1_000])->assertForbidden();
    }
}
