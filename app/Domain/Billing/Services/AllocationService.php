<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Customer;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Models\PaymentAllocation;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only code that changes an invoice's paid amount (plan §2.4).
 * Money goes to instalments in order (oldest first); whatever an invoice
 * cannot take is returned to the caller, who turns it into customer credit.
 */
class AllocationService
{
    /** @return int the amount actually applied */
    public function allocate(Invoice $invoice, int $amount, string $source, ?Payment $payment = null, ?User $actor = null): int
    {
        if ($amount <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($invoice, $amount, $source, $payment, $actor) {
            $invoice = Invoice::whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (! $invoice->status->acceptsPayment()) {
                return 0;
            }

            $toApply = min($amount, $invoice->balance());
            if ($toApply <= 0) {
                return 0;
            }

            $rows = [];
            $remaining = $toApply;
            $installments = $invoice->installments()->lockForUpdate()->get();

            foreach ($installments as $installment) {
                if ($remaining === 0) {
                    break;
                }
                $take = min($remaining, $installment->balance());
                if ($take > 0) {
                    $installment->increment('amount_paid', $take);
                    $rows[] = [$installment->id, $take];
                    $remaining -= $take;
                }
            }

            // No instalments (or rounding left over): allocate against the invoice itself.
            if ($remaining > 0) {
                $rows[] = [null, $remaining];
            }

            foreach ($rows as [$installmentId, $value]) {
                PaymentAllocation::create([
                    'business_id' => $invoice->business_id,
                    'invoice_id' => $invoice->id,
                    'installment_id' => $installmentId,
                    'payment_id' => $payment?->id,
                    'source' => $source,
                    'amount' => $value,
                    'created_by' => $actor?->id,
                ]);
            }

            $invoice->amount_paid += $toApply;
            $invoice->status = $invoice->amount_paid >= $invoice->amount_due ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid;
            $invoice->version++;
            $invoice->save();

            return $toApply;
        });
    }

    /**
     * Undo every allocation a payment made (used when a manual entry is voided).
     * Instalments and invoices go back to what they were before the payment.
     */
    public function reverse(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            foreach (PaymentAllocation::where('payment_id', $payment->id)->get() as $allocation) {
                $invoice = Invoice::whereKey($allocation->invoice_id)->lockForUpdate()->firstOrFail();

                if ($allocation->installment_id) {
                    $invoice->installments()->whereKey($allocation->installment_id)->decrement('amount_paid', $allocation->amount);
                }

                $invoice->amount_paid = max(0, $invoice->amount_paid - $allocation->amount);
                $invoice->status = match (true) {
                    $invoice->amount_paid >= $invoice->amount_due => InvoiceStatus::Paid,
                    $invoice->amount_paid > 0 => InvoiceStatus::PartiallyPaid,
                    default => InvoiceStatus::Open,
                };
                $invoice->version++;
                $invoice->save();
                $allocation->delete();
            }
        });
    }

    /** Use a customer's credit on one invoice. @return int the amount applied */
    public function applyCredit(Invoice $invoice, ?int $max = null, ?User $actor = null): int
    {
        return DB::transaction(function () use ($invoice, $max, $actor) {
            $customer = Customer::whereKey($invoice->customer_id)->lockForUpdate()->firstOrFail();
            $available = $max === null ? $customer->credit_balance : min($max, $customer->credit_balance);

            $applied = $this->allocate($invoice, $available, 'credit', null, $actor);

            if ($applied > 0) {
                $customer->decrement('credit_balance', $applied);
            }

            return $applied;
        });
    }
}
