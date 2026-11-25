<?php

namespace App\Http\Resources;

use App\Domain\Billing\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'external_reference' => $this->external_reference,
            'description' => $this->description,
            'currency' => $this->currency,
            'amount_due' => $this->amount_due,
            'amount_paid' => $this->amount_paid,
            'balance' => $this->balance(),
            'allow_partial' => $this->allow_partial,
            'min_amount' => $this->min_amount,
            'minimum_payment' => $this->minimumPayment(),
            'due_date' => $this->due_date?->toDateString(),
            'control_number' => $this->whenLoaded('controlNumber', fn () => $this->controlNumber?->number),
            'status' => $this->status->value,
            'overdue' => $this->isOverdue(),
            'void_reason' => $this->void_reason,
            'customer' => $this->whenLoaded('customer', fn () => new CustomerResource($this->customer)),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'description' => $l->description, 'quantity' => $l->quantity, 'unit_amount' => $l->unit_amount, 'amount' => $l->amount,
            ])),
            'installments' => $this->whenLoaded('installments', fn () => $this->installments->map(fn ($i) => [
                'sequence' => $i->sequence,
                'due_date' => $i->due_date?->toDateString(),
                'amount' => $i->amount,
                'amount_paid' => $i->amount_paid,
                'status' => $i->status(),
            ])),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'source' => $a->source,
                'amount' => $a->amount,
                'payment_id' => $a->payment_id,
                'installment' => $a->installment?->sequence,
                'created_at' => $a->created_at?->toIso8601String(),
            ])),
            'public_url' => $this->publicUrl(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
