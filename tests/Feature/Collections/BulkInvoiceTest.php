<?php

namespace Tests\Feature\Collections;

use App\Domain\Billing\Models\Customer;
use App\Domain\Billing\Models\Invoice;

class BulkInvoiceTest extends CollectionsTestCase
{
    private function payload(array $customerIds, array $extra = []): array
    {
        return $extra + [
            'name' => 'Term 1 2027 fees',
            'idempotency_key' => 'batch-key-1',
            'customer_ids' => $customerIds,
            'due_date' => '2027-01-15',
            'lines' => [['description' => 'Tuition', 'unit_amount' => 450_000], ['description' => 'Meals', 'unit_amount' => 150_000]],
            'installments' => [['amount' => 300_000, 'due_date' => '2027-01-15'], ['amount' => 300_000, 'due_date' => '2027-03-15']],
        ];
    }

    public function test_bill_a_whole_group_or_chosen_members_once(): void
    {
        $group = $this->postJson($this->url('groups'), ['name' => 'Form 2B'])->json('data.id');
        $pupils = collect(['Asha', 'Baraka', 'Chausiku', 'Daudi'])->map(fn ($n) => $this->customer(['name' => $n, 'group_id' => $group]));

        // Leave Daudi out (scholarship): only the chosen members are billed.
        $chosen = $pupils->take(3)->pluck('id')->all();
        $res = $this->postJson($this->url('invoices/bulk'), $this->payload($chosen, ['group_id' => $group]))->assertCreated()
            ->assertJsonPath('data.created', 3)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('data.batch.invoices_count', 3)
            ->assertJsonPath('data.batch.total_amount', 1_800_000);
        $batchId = $res->json('data.batch.id');

        $this->assertSame(3, Invoice::where('batch_id', $batchId)->count());
        $this->assertSame(0, Invoice::where('customer_id', $pupils[3]['id'])->count());

        // Each invoice is complete: items, instalments, its own control number, the batch name as note.
        $inv = $this->getJson($this->url('invoices/'.Invoice::where('batch_id', $batchId)->first()->id))
            ->assertJsonPath('data.amount_due', 600_000)
            ->assertJsonPath('data.description', 'Term 1 2027 fees')
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonCount(2, 'data.installments')
            ->json('data');
        $this->assertMatchesRegularExpression('/^\d{11}$/', $inv['control_number']);

        // A retry or double-click creates nothing new.
        $this->postJson($this->url('invoices/bulk'), $this->payload($chosen, ['group_id' => $group]))
            ->assertCreated()->assertJsonPath('data.created', 0)->assertJsonPath('data.skipped', 3)->assertJsonPath('data.batch.id', $batchId);

        // Adding the forgotten pupil to the same batch bills only them.
        $this->postJson($this->url('invoices/bulk'), $this->payload($pupils->pluck('id')->all()))
            ->assertJsonPath('data.created', 1)->assertJsonPath('data.batch.invoices_count', 4);

        // Filter and batch list.
        $this->getJson($this->url("invoices?batch_id={$batchId}"))->assertJsonCount(4, 'data');
        $this->getJson($this->url('invoice-batches'))->assertJsonPath('data.0.name', 'Term 1 2027 fees')->assertJsonPath('data.0.group', 'Form 2B');
        $this->getJson($this->url('groups'))->assertJsonPath('data.0.invoiced', 2_400_000);
    }

    public function test_existing_credit_applies_and_bad_input_is_refused(): void
    {
        $asha = $this->customer(['name' => 'Asha']);
        Customer::whereKey($asha['id'])->update(['credit_balance' => 100_000]);

        $this->postJson($this->url('invoices/bulk'), $this->payload([$asha['id']]))->assertCreated();
        $this->assertSame(100_000, Invoice::where('customer_id', $asha['id'])->sole()->amount_paid);

        $this->postJson($this->url('invoices/bulk'), $this->payload([], ['idempotency_key' => 'k2']))->assertUnprocessable()->assertJsonValidationErrors('customer_ids');
        $this->postJson($this->url('invoices/bulk'), $this->payload(['01JNOTMINE000000000000000'], ['idempotency_key' => 'k3']))->assertUnprocessable();
        $this->postJson($this->url('invoices/bulk'), $this->payload([$asha['id']], ['idempotency_key' => 'k4', 'installments' => [['amount' => 1]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('installments');
        $this->assertSame(1, Invoice::count()); // nothing half-created
    }
}
