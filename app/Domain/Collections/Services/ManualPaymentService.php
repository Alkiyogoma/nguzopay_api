<?php

namespace App\Domain\Collections\Services;

use App\Domain\Billing\Models\Customer;
use App\Domain\Billing\Services\AllocationService;
use App\Domain\Business\Models\Business;
use App\Domain\Collections\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Payments the merchant received outside NguzoPay (cash at the office, a bank
 * deposit, a cheque…) recorded by hand so invoices and balances stay right.
 * They never touch NguzoPay's ledger, fees or settlements. A mistaken entry is
 * voided, which undoes its allocations; nothing is deleted.
 */
class ManualPaymentService
{
    public function __construct(
        private readonly PaymentRecorder $recorder,
        private readonly AllocationService $allocations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{customer_id?: ?string, invoice_id?: ?string, amount: int, method: string, paid_on?: ?string, reference?: ?string, note?: ?string, payer_name?: ?string}  $data
     */
    public function record(Business $business, array $data, User $actor): Payment
    {
        $invoice = ! empty($data['invoice_id']) ? $business->invoices()->with('customer')->find($data['invoice_id']) : null;
        if (! empty($data['invoice_id']) && (! $invoice || ! $invoice->status->acceptsPayment())) {
            throw ValidationException::withMessages(['invoice_id' => 'Choose an open invoice.']);
        }

        $customer = $invoice?->customer ?? (! empty($data['customer_id']) ? $business->customers()->find($data['customer_id']) : null);
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => 'Choose who paid.']);
        }

        $tz = config('nguzopay.settlement.timezone');
        $paidOn = ! empty($data['paid_on']) ? CarbonImmutable::parse($data['paid_on'], $tz) : CarbonImmutable::now($tz);
        if ($paidOn->isAfter(CarbonImmutable::now($tz)->endOfDay())) {
            throw ValidationException::withMessages(['paid_on' => 'The payment date cannot be in the future.']);
        }
        // Today keeps the current time; an earlier day is recorded at noon of that day.
        $paidAt = $paidOn->isToday() ? CarbonImmutable::now() : $paidOn->setTime(12, 0)->utc();

        return DB::transaction(function () use ($business, $customer, $invoice, $data, $paidAt, $actor) {
            $payment = $this->recorder->record($business, $customer, [
                'provider' => Payment::MANUAL,
                'provider_reference' => 'MAN-'.Str::upper((string) Str::ulid()),
                'channel' => $data['method'],
                'payer_name' => $data['payer_name'] ?? null,
                'amount' => (int) $data['amount'],
                'paid_at' => $paidAt,
                'details' => array_filter([
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'note' => $data['note'] ?? null,
                    'recorded_by' => $actor->name ?? $actor->email,
                ]),
            ], 0, $invoice, oldestFirst: $invoice === null);

            $this->audit->record('payment.recorded_manually', $payment, [
                'amount' => $payment->amount, 'method' => $data['method'], 'invoice' => $invoice?->number, 'receipt' => $payment->receipt_number,
            ], actor: $actor, business: $business);

            return $payment;
        });
    }

    public function void(Payment $payment, string $reason, User $actor): Payment
    {
        if (! $payment->isManual()) {
            throw ValidationException::withMessages(['payment' => 'Only payments recorded by hand can be voided. Bank and mobile-money payments need a refund.']);
        }
        if ($payment->status === 'voided') {
            throw ValidationException::withMessages(['payment' => 'This payment is already voided.']);
        }

        return DB::transaction(function () use ($payment, $reason, $actor) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->credited_amount > 0) {
                $customer = Customer::whereKey($payment->customer_id)->lockForUpdate()->firstOrFail();
                if ($customer->credit_balance < $payment->credited_amount) {
                    throw ValidationException::withMessages(['payment' => 'Part of this payment became credit that has already been used on another invoice. Void that use first, or record an adjustment.']);
                }
                $customer->decrement('credit_balance', $payment->credited_amount);
            }

            $this->allocations->reverse($payment);

            $payment->forceFill([
                'status' => 'voided',
                'details' => array_merge($payment->details ?? [], [
                    'void_reason' => $reason,
                    'voided_by' => $actor->name ?? $actor->email,
                    'voided_at' => now()->toIso8601String(),
                ]),
            ])->save();

            $this->audit->record('payment.voided', $payment, ['reason' => $reason, 'amount' => $payment->amount, 'receipt' => $payment->receipt_number], actor: $actor);

            return $payment;
        });
    }
}
