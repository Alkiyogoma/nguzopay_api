<?php

namespace App\Domain\Collections\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Customer;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Services\AllocationService;
use App\Domain\Business\Models\Business;
use App\Domain\Collections\Models\Payment;
use App\Domain\Ledger\Services\Ledger;
use App\Domain\Platform\Services\AuditLogger;
use Illuminate\Support\Collection;

/**
 * Records money that has arrived, from any channel (mobile-money push or a
 * bank bill payment): payment row with receipt number, fees, allocation,
 * customer credit, ledger entry and audit. Call inside a transaction.
 */
class PaymentRecorder
{
    public function __construct(
        private readonly AllocationService $allocations,
        private readonly FeeCalculator $fees,
        private readonly Ledger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  payment columns (provider, provider_reference, amount, …)
     * @param  Invoice|null  $invoice  pay this invoice first
     * @param  bool  $oldestFirst  with no invoice, pay the customer's open invoices oldest first
     */
    public function record(Business $business, ?Customer $customer, array $attributes, ?int $reportedProviderFee, ?Invoice $invoice = null, bool $oldestFirst = false): Payment
    {
        $amount = (int) $attributes['amount'];
        // A manual payment never passed through NguzoPay: no fees, no ledger entry, no settlement.
        $offPlatform = ($attributes['provider'] ?? null) === Payment::MANUAL;
        $providerFee = $offPlatform ? 0 : ($reportedProviderFee ?? $this->fees->estimatedProviderFee($attributes['provider'], $amount));
        $split = $offPlatform ? ['provider_fee' => 0, 'platform_fee' => 0, 'net' => $amount] : $this->fees->split($business, $amount, $providerFee);

        $payment = Payment::create($attributes + [
            'business_id' => $business->id,
            'customer_id' => $customer?->id,
            'receipt_number' => $this->receiptNumber(),
            'provider_fee' => $split['provider_fee'],
            'provider_fee_estimated' => ! $offPlatform && $reportedProviderFee === null,
            'platform_fee' => $split['platform_fee'],
            'net_amount' => $split['net'],
            'status' => 'succeeded',
            'paid_at' => $attributes['paid_at'] ?? now(),
        ]);

        // Pay the invoice (or the oldest invoices) first; anything left is customer credit (plan §2.4).
        $allocated = 0;
        $targets = $invoice ? collect([$invoice]) : ($oldestFirst && $customer ? $this->openInvoices($customer) : collect());
        foreach ($targets as $target) {
            if ($allocated >= $amount) {
                break;
            }
            $allocated += $this->allocations->allocate($target, $amount - $allocated, 'payment', $payment);
        }

        $credited = $amount - $allocated;
        if ($credited > 0 && $customer) {
            Customer::whereKey($customer->id)->increment('credit_balance', $credited);
        }
        $payment->forceFill(['allocated_amount' => $allocated, 'credited_amount' => $credited])->save();

        // NguzoPay's books: the provider holds gross − its fee; we owe the merchant the net; the rest is our fee.
        if (! $offPlatform) {
            $this->ledger->post('payment.captured', $payment, [
                [$this->ledger->providerClearing($attributes['provider']), $amount - $split['provider_fee'], 0],
                [$this->ledger->merchantPayable($business), 0, $split['net']],
                [$this->ledger->feeRevenue(), 0, $split['platform_fee']],
            ], "Payment {$payment->id}");
        }

        $this->audit->record('payment.received', $payment, array_filter([
            'amount' => $amount,
            'provider' => $attributes['provider'],
            'receipt' => $payment->receipt_number,
            'invoice' => $invoice?->number,
            'credited' => $credited ?: null,
        ]), business: $business);

        return $payment;
    }

    /** @return Collection<int, Invoice> */
    private function openInvoices(Customer $customer)
    {
        return Invoice::where('customer_id', $customer->id)
            ->whereIn('status', [InvoiceStatus::Open, InvoiceStatus::PartiallyPaid])
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('created_at')
            ->get();
    }

    /** 16 digits, unique: yymmdd + 10 random digits. Numeric so banks and SMS show it cleanly. */
    private function receiptNumber(): string
    {
        do {
            $number = now()->format('ymd').str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        } while (Payment::where('receipt_number', $number)->exists());

        return $number;
    }
}
