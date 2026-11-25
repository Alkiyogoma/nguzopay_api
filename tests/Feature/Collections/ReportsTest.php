<?php

namespace Tests\Feature\Collections;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Business\Enums\StaffRole;
use App\Domain\Collections\Models\Payment;
use App\Domain\Identity\Models\User;
use Laravel\Sanctum\Sanctum;
use ZipArchive;

class ReportsTest extends CollectionsTestCase
{
    private function seedData(): array
    {
        $asha = $this->customer(['name' => 'Asha Mussa']);
        $juma = $this->customer(['name' => 'Juma Ali']);
        $paid = $this->invoice($asha['id'], 100_000);
        $open = $this->invoice($juma['id'], 50_000, ['due_date' => '2026-01-01']);
        $intent = $this->requestPayment(['invoice_id' => $paid['id'], 'amount' => 100_000])->json('data');
        $this->simulate($intent['id']);

        return compact('asha', 'juma', 'paid', 'open');
    }

    public function test_every_list_returns_summary_cards_for_its_filters(): void
    {
        $this->seedData();

        $cards = fn (string $path) => collect($this->getJson($this->url($path))->assertOk()->json('summary'))->pluck('value', 'key');

        $this->assertSame(['customers' => 2, 'owing' => 1, 'owed' => 50_000, 'credit' => 0], $cards('customers')->all());
        $this->assertSame(['customers' => 1, 'owing' => 1, 'owed' => 50_000, 'credit' => 0], $cards('customers?balance=owing')->all());
        $this->assertSame(['invoiced' => 150_000, 'collected' => 100_000, 'outstanding' => 50_000, 'overdue' => 50_000], $cards('invoices')->all());
        $this->assertSame(100_000, $cards('payments')['received']);
        $this->assertSame(1, $cards('payment-intents')['sent']);
        $this->assertSame(100, (int) $cards('payment-intents')['success']);
        $this->assertSame(0, $cards('settlements')['net']);

        $this->getJson($this->url('customers?balance=owing'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Juma Ali');
        $this->getJson($this->url('payments?method=bank'))->assertJsonCount(0, 'data');
    }

    public function test_date_range_filters_in_east_africa_time(): void
    {
        $data = $this->seedData();
        Invoice::whereKey($data['open']['id'])->update(['created_at' => '2026-10-31 21:30:00']); // 1 Nov 00:30 EAT

        $this->getJson($this->url('invoices?from=2026-11-01&to=2026-11-01'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $data['open']['id']);
        $this->getJson($this->url('invoices?from=2026-10-01&to=2026-10-31'))->assertJsonCount(0, 'data');
        $this->getJson($this->url('invoices?from=2026-11-05&to=2026-11-01'))->assertUnprocessable()->assertJsonValidationErrors('to');

        Payment::query()->update(['paid_at' => now()->subDays(40)]);
        $this->getJson($this->url('payments?from='.now()->subDays(7)->toDateString()))->assertJsonCount(0, 'data');
    }

    public function test_excel_export_has_title_cards_header_and_rows(): void
    {
        $this->seedData();

        $response = $this->get($this->url('reports/invoices/export?status=unpaid'))->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('content-type'));
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));

        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file) === true, 'a valid zip/xlsx');
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertStringContainsString('Invoices · '.e($this->business->displayName()), $sheet);
        $this->assertStringContainsString('Status: Unpaid', $sheet);
        $this->assertStringContainsString('Control number', $sheet);
        $this->assertStringContainsString('Juma Ali', $sheet);
        $this->assertStringNotContainsString('Asha Mussa', $sheet); // paid, filtered out
        $this->assertStringContainsString('<v>50000</v>', $sheet);  // amounts are real numbers in Excel
    }

    public function test_print_view_data_is_formatted_with_filters(): void
    {
        $this->seedData();

        $this->getJson($this->url('reports/payments/export?format=json&from=2026-01-01'))->assertOk()
            ->assertJsonPath('data.title', 'Payments')
            ->assertJsonPath('data.business', $this->business->displayName())
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.truncated', false)
            ->assertJsonPath('data.summary.0.display', 'TZS 100,000')
            ->assertJsonPath('data.columns.0.label', 'Date')
            ->assertJsonPath('data.rows.0.amount', 'TZS 100,000')
            ->assertJsonPath('data.filters.0', 'Dates: 1 Jan 2026 – today');
    }

    public function test_exports_follow_permissions(): void
    {
        $this->seedData();

        $viewer = User::factory()->create();
        $this->business->members()->create(['user_id' => $viewer->id, 'role' => StaffRole::Viewer]);
        Sanctum::actingAs($viewer);
        $this->get($this->url('reports/customers/export'))->assertOk();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson($this->url('reports/customers/export'))->assertForbidden();
        $this->getJson($this->url('reports/nope/export'))->assertNotFound();
    }
}
