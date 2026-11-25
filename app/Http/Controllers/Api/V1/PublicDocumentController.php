<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Business\Models\Business;
use App\Domain\Collections\Models\Payment;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * What the QR code on a printed invoice or receipt opens. Anyone with the link
 * sees that one document: no session, no ids, nothing else about the customer
 * (no phone or email) or the business's other records.
 */
class PublicDocumentController extends Controller
{
    public function invoice(string $token): JsonResponse
    {
        $invoice = Invoice::where('public_token', $token)
            ->with('business', 'customer.accountNumber', 'lines', 'installments', 'controlNumber', 'allocations.payment')
            ->firstOrFail();

        $open = in_array($invoice->status, [InvoiceStatus::Open, InvoiceStatus::PartiallyPaid], true);

        return response()->json(['data' => [
            'business' => $this->business($invoice->business),
            'invoice' => [
                'number' => $invoice->number,
                'description' => $invoice->description,
                'status' => $invoice->status->value,
                'overdue' => $invoice->isOverdue(),
                'currency' => $invoice->currency,
                'amount_due' => $invoice->amount_due,
                'amount_paid' => $invoice->amount_paid,
                'balance' => $invoice->balance(),
                'allow_partial' => $invoice->allow_partial,
                'min_amount' => $invoice->min_amount,
                'minimum_payment' => $open ? $invoice->minimumPayment() : 0,
                'due_date' => $invoice->due_date?->toDateString(),
                'created_at' => $invoice->created_at?->toIso8601String(),
                'void_reason' => $invoice->status === InvoiceStatus::Void ? $invoice->void_reason : null,
                'control_number' => $invoice->controlNumber?->number,
                'customer' => ['name' => $invoice->customer?->name, 'reference' => $invoice->customer?->reference],
                'lines' => $invoice->lines->map(fn ($l) => ['description' => $l->description, 'quantity' => $l->quantity, 'unit_amount' => $l->unit_amount, 'amount' => $l->amount]),
                'installments' => $invoice->installments->map(fn ($i) => [
                    'sequence' => $i->sequence, 'due_date' => $i->due_date?->toDateString(), 'amount' => $i->amount, 'amount_paid' => $i->amount_paid, 'status' => $i->status(),
                ]),
                // Only payments that still count; each links to its own public receipt.
                'payments' => $invoice->allocations
                    ->filter(fn ($a) => $a->payment && $a->payment->status === 'succeeded')
                    ->groupBy('payment_id') // one row per payment, even across instalments
                    ->map(fn ($group) => [
                        'amount' => (int) $group->sum('amount'),
                        'paid_at' => $group->first()->payment->paid_at?->toIso8601String(),
                        'receipt_number' => $group->first()->payment->receipt_number,
                        'receipt_url' => $group->first()->payment->receipt_number ? $group->first()->payment->publicUrl() : null,
                    ])->sortBy('paid_at')->values(),
                'public_url' => $invoice->publicUrl(),
            ],
            'checked_at' => now()->toIso8601String(),
        ]]);
    }

    public function receipt(string $token): JsonResponse
    {
        $payment = Payment::where('public_token', $token)->whereNotNull('receipt_number')
            ->with('business', 'customer', 'allocations.invoice')
            ->firstOrFail();

        return response()->json(['data' => [
            'business' => $this->business($payment->business),
            'receipt' => [
                'receipt_number' => $payment->receipt_number,
                'status' => $payment->status,                // succeeded | voided
                'amount' => $payment->amount,
                'credited_amount' => $payment->credited_amount,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'method' => $payment->isManual()
                    ? (Payment::METHODS[$payment->channel] ?? 'Recorded payment')
                    : ($payment->channel === 'bank' ? strtoupper($payment->provider).' Bank' : ($payment->operator?->label() ?? 'Mobile money')),
                'reference' => $payment->isManual() ? ($payment->details['reference'] ?? null) : $payment->provider_reference,
                'customer' => ['name' => $payment->customer?->name ?? $payment->payer_name],
                // One line per invoice, even when the payment covered several of its instalments.
                'invoices' => $payment->allocations->filter(fn ($a) => $a->invoice)->groupBy('invoice_id')->map(fn ($group) => [
                    'number' => $group->first()->invoice->number,
                    'amount' => (int) $group->sum('amount'),
                    'invoice_total' => $group->first()->invoice->amount_due,
                    'balance_now' => $group->first()->invoice->balance(),
                    'status' => $group->first()->invoice->status->value,
                    'url' => $group->first()->invoice->publicUrl(),
                ])->values(),
                'void_reason' => $payment->status === 'voided' ? ($payment->details['void_reason'] ?? null) : null,
            ],
            'checked_at' => now()->toIso8601String(),
        ]]);
    }

    /** The letterhead: what the printed document already shows. */
    private function business(Business $b): array
    {
        return [
            'display_name' => $b->displayName(),
            'legal_name' => $b->legal_name,
            'logo_url' => $b->logo_path ? Storage::disk('public')->url($b->logo_path) : null,
            'email' => $b->email,
            'phone' => $b->phone,
            'physical_address' => $b->physical_address,
            'postal_address' => $b->postal_address,
            'region' => $b->region,
            'district' => $b->district,
            'tin' => $b->tin,
            'code' => $b->code,
        ];
    }
}
