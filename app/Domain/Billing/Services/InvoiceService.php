<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Customer;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceBatch;
use App\Domain\Business\Models\Business;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AllocationService $allocations,
        private readonly ControlNumberService $controlNumbers,
    ) {}

    /**
     * Create an invoice. With an external_reference that already exists, the
     * existing invoice is returned unchanged, so merchant systems can retry safely.
     *
     * @param  array{customer_id: string, lines: list<array{description: string, quantity?: int, unit_amount: int}>, installments?: list<array{due_date?: ?string, amount: int}>, due_date?: ?string, description?: ?string, allow_partial?: bool, min_amount?: ?int, external_reference?: ?string}  $data
     */
    public function create(Business $business, array $data, ?User $actor = null): Invoice
    {
        if (! empty($data['external_reference'])) {
            $existing = $business->invoices()->where('external_reference', $data['external_reference'])->first();
            if ($existing) {
                return $existing;
            }
        }

        $customer = Customer::where('business_id', $business->id)->findOrFail($data['customer_id']);

        $lines = array_map(fn ($line, $i) => [
            'description' => $line['description'],
            'quantity' => (int) ($line['quantity'] ?? 1),
            'unit_amount' => (int) $line['unit_amount'],
            'amount' => (int) ($line['quantity'] ?? 1) * (int) $line['unit_amount'],
            'position' => $i,
        ], $data['lines'], array_keys($data['lines']));

        $total = array_sum(array_column($lines, 'amount'));
        if ($total <= 0) {
            throw ValidationException::withMessages(['lines' => 'The invoice total must be more than zero.']);
        }

        $installments = $data['installments'] ?? [];
        if ($installments && array_sum(array_map(fn ($i) => (int) $i['amount'], $installments)) !== $total) {
            throw ValidationException::withMessages(['installments' => 'Instalments must add up to the invoice total ('.number_format($total).' TZS).']);
        }

        if (! empty($data['min_amount']) && $data['min_amount'] > $total) {
            throw ValidationException::withMessages(['min_amount' => 'The minimum payment cannot be more than the total.']);
        }

        $invoice = DB::transaction(function () use ($business, $customer, $data, $lines, $installments, $total, $actor) {
            $locked = Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $number = 'INV-'.str_pad((string) $locked->next_invoice_number, 6, '0', STR_PAD_LEFT);
            Business::whereKey($business->id)->increment('next_invoice_number');

            $invoice = new Invoice([
                'external_reference' => $data['external_reference'] ?? null,
                'description' => $data['description'] ?? null,
                'allow_partial' => $data['allow_partial'] ?? true,
                'min_amount' => $data['min_amount'] ?? null,
                'due_date' => $data['due_date'] ?? ($installments[0]['due_date'] ?? null),
            ]);
            $invoice->forceFill([
                'business_id' => $business->id,
                'customer_id' => $customer->id,
                'number' => $number,
                'amount_due' => $total,
                'created_by' => $actor?->id,
                'batch_id' => $data['batch_id'] ?? null,
            ])->save();

            $invoice->lines()->createMany($lines);
            foreach (array_values($installments) as $i => $installment) {
                $invoice->installments()->create([
                    'sequence' => $i + 1,
                    'due_date' => $installment['due_date'] ?? null,
                    'amount' => (int) $installment['amount'],
                ]);
            }

            $this->audit->record('invoice.created', $invoice, ['number' => $number, 'amount' => $total], actor: $actor, business: $business);

            return $invoice;
        });

        // Every invoice can be paid at the bank with its own control number.
        $this->controlNumbers->forInvoice($invoice);

        // Overpayments auto-apply to the next invoice (plan §2.4).
        if ($customer->credit_balance > 0) {
            $applied = $this->allocations->applyCredit($invoice, null, $actor);
            $this->audit->record('invoice.credit_applied', $invoice, ['amount' => $applied, 'automatic' => true], actor: $actor, business: $business);
        }

        return $invoice->refresh();
    }

    /**
     * The same invoice for many customers (a whole class, or chosen members of it).
     * Safe to retry: the batch is keyed by the client's idempotency key and each
     * customer's invoice by batch + customer, so nobody is ever billed twice.
     *
     * @param  array{name: string, idempotency_key: string, customer_ids: list<string>, group_id?: ?string}  $data  plus the invoice fields of create()
     * @return array{batch: InvoiceBatch, created: int, skipped: int}
     */
    public function createBatch(Business $business, array $data, ?User $actor = null): array
    {
        $customers = Customer::where('business_id', $business->id)->whereIn('id', array_unique($data['customer_ids']))->orderBy('name')->get();
        if ($customers->isEmpty()) {
            throw ValidationException::withMessages(['customer_ids' => 'Choose at least one customer.']);
        }

        return DB::transaction(function () use ($business, $data, $actor, $customers) {
            $batch = InvoiceBatch::firstOrCreate(
                ['business_id' => $business->id, 'idempotency_key' => $data['idempotency_key']],
                ['name' => $data['name'], 'group_id' => $data['group_id'] ?? null, 'created_by' => $actor?->id],
            );

            $created = 0;
            foreach ($customers as $customer) {
                $invoice = $this->create($business, [
                    ...$data,
                    'customer_id' => $customer->id,
                    'batch_id' => $batch->id,
                    'external_reference' => "batch:{$batch->id}:{$customer->id}",
                    'description' => $data['description'] ?? $data['name'],
                ], $actor);
                $created += $invoice->wasRecentlyCreated ? 1 : 0;
            }

            $batch->forceFill([
                'invoices_count' => $batch->invoices()->count(),
                'total_amount' => (int) $batch->invoices()->sum('amount_due'),
            ])->save();

            $this->audit->record('invoice_batch.created', $batch, ['name' => $batch->name, 'invoices' => $created, 'total' => $batch->total_amount], actor: $actor, business: $business);

            return ['batch' => $batch, 'created' => $created, 'skipped' => $customers->count() - $created];
        });
    }

    public function void(Invoice $invoice, string $reason, User $actor): Invoice
    {
        if (! $invoice->status->acceptsPayment()) {
            throw ValidationException::withMessages(['status' => 'Only an unpaid open invoice can be voided.']);
        }
        if ($invoice->amount_paid > 0) {
            throw ValidationException::withMessages(['status' => 'This invoice has payments on it. Refund or credit them first.']);
        }

        $invoice->forceFill([
            'status' => InvoiceStatus::Void,
            'voided_at' => now(),
            'void_reason' => $reason,
            'version' => $invoice->version + 1,
        ])->save();

        $this->audit->record('invoice.voided', $invoice, ['reason' => $reason], actor: $actor);

        return $invoice;
    }

    public function applyCredit(Invoice $invoice, ?int $amount, User $actor): int
    {
        $applied = $this->allocations->applyCredit($invoice, $amount, $actor);

        if ($applied === 0) {
            throw ValidationException::withMessages(['amount' => 'There is no credit to apply, or the invoice is already paid.']);
        }

        $this->audit->record('invoice.credit_applied', $invoice, ['amount' => $applied], actor: $actor);

        return $applied;
    }
}
