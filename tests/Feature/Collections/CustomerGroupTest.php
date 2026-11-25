<?php

namespace Tests\Feature\Collections;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Business\Enums\StaffRole;
use App\Domain\Identity\Models\User;
use Laravel\Sanctum\Sanctum;

class CustomerGroupTest extends CollectionsTestCase
{
    private function group(string $name): string
    {
        return $this->postJson($this->url('groups'), ['name' => $name])->assertCreated()->json('data.id');
    }

    public function test_groups_track_paid_outstanding_and_overdue_per_class(): void
    {
        $form1 = $this->group('Form 1A');
        $form2 = $this->group('Form 2B');

        $asha = $this->customer(['name' => 'Asha', 'group_id' => $form1]);
        $juma = $this->customer(['name' => 'Juma', 'group_id' => $form1]);
        $neema = $this->customer(['name' => 'Neema', 'group_id' => $form2]);
        $this->customer(['name' => 'No class']);
        $this->assertSame('Form 1A', $asha['group']['name']);

        $a = $this->invoice($asha['id'], 100_000);
        $this->invoice($juma['id'], 80_000, ['due_date' => '2026-01-10']); // overdue
        $this->invoice($neema['id'], 50_000);
        $this->postJson($this->url('payments'), ['invoice_id' => $a['id'], 'amount' => 60_000, 'method' => 'cash'])->assertCreated();

        $res = $this->getJson($this->url('groups'))->assertOk()->assertJsonPath('ungrouped', 1);
        $rows = collect($res->json('data'))->keyBy('name');
        $this->assertSame(['customers' => 2, 'invoiced' => 180_000, 'paid' => 60_000, 'outstanding' => 120_000, 'overdue' => 80_000, 'rate' => 33.3],
            collect($rows['Form 1A'])->only(['customers', 'invoiced', 'paid', 'outstanding', 'overdue', 'rate'])->all());
        $this->assertSame(50_000, $rows['Form 2B']['outstanding']);
        $this->assertSame(170_000, collect($res->json('summary'))->firstWhere('key', 'outstanding')['value']);

        // The date range counts only invoices issued in it.
        Invoice::where('customer_id', $neema['id'])->update(['created_at' => '2026-01-05 10:00:00']);
        $rows = collect($this->getJson($this->url('groups?from=2026-11-01'))->json('data'))->keyBy('name');
        $this->assertSame(0, $rows['Form 2B']['invoiced']);

        // Group filter on the other pages.
        $this->getJson($this->url("customers?group_id={$form1}"))->assertJsonCount(2, 'data');
        $this->getJson($this->url('customers?group_id=none'))->assertJsonCount(1, 'data');
        $this->getJson($this->url("invoices?group_id={$form1}"))->assertJsonCount(2, 'data')->assertJsonPath('summary.2.value', 120_000);
        $this->getJson($this->url("payments?group_id={$form2}"))->assertJsonCount(0, 'data');
        $this->getJson($this->url("reports/invoices/export?format=json&group_id={$form1}"))->assertJsonPath('data.filters.0', 'Group: Form 1A');
        $this->getJson($this->url('reports/groups/export?format=json'))->assertJsonPath('data.rows.0.name', 'Form 1A');
    }

    public function test_bulk_move_rename_and_delete_keep_customers(): void
    {
        $from = $this->group('Standard 4');
        $to = $this->group('Standard 5');
        $ids = collect(range(1, 3))->map(fn ($i) => $this->customer(['name' => "Pupil {$i}", 'group_id' => $from])['id'])->all();

        $this->postJson($this->url('customers/move-group'), ['customer_ids' => $ids, 'group_id' => $to])->assertOk()->assertJsonPath('moved', 3);
        $this->getJson($this->url("customers?group_id={$to}"))->assertJsonCount(3, 'data');

        $this->postJson($this->url('groups'), ['name' => 'Standard 5'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson($this->url("groups/{$to}"), ['name' => 'Std 5 (2027)'])->assertOk()->assertJsonPath('data.name', 'Std 5 (2027)');

        $this->deleteJson($this->url("groups/{$to}"))->assertOk()->assertJsonPath('customers', 3);
        $this->getJson($this->url('customers?group_id=none'))->assertJsonCount(3, 'data');
    }

    public function test_groups_belong_to_one_business(): void
    {
        $mine = $this->group('Form 1A');
        $customer = $this->customer();

        $other = User::factory()->create();
        $business = $this->businessWith($other, StaffRole::Owner, ['status' => 'active']);
        Sanctum::actingAs($other);

        $this->patchJson("/api/v1/businesses/{$business->id}/groups/{$mine}", ['name' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/businesses/{$business->id}/customers/move-group", ['customer_ids' => [$customer['id']], 'group_id' => $mine])
            ->assertUnprocessable()->assertJsonValidationErrors('group_id');
    }
}
