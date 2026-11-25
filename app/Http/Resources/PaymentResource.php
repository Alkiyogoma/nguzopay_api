<?php

namespace App\Http\Resources;

use App\Domain\Collections\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'manual' => $this->isManual(),
            'method_label' => $this->isManual() ? (Payment::METHODS[$this->channel] ?? 'Manual') : null,
            'provider' => $this->provider,
            'provider_reference' => $this->provider_reference,
            'channel' => $this->channel,
            'operator' => $this->operator?->value,
            'operator_label' => $this->operator?->label(),
            'payer_phone' => $this->payer_phone,
            'payer_name' => $this->payer_name,
            'details' => $this->details,
            'amount' => $this->amount,
            'provider_fee' => $this->provider_fee,
            'provider_fee_estimated' => $this->provider_fee_estimated,
            'platform_fee' => $this->platform_fee,
            'net_amount' => $this->net_amount,
            'allocated_amount' => $this->allocated_amount,
            'credited_amount' => $this->credited_amount,
            'status' => $this->status,
            'settlement_id' => $this->settlement_id,
            'payment_intent_id' => $this->payment_intent_id,
            'business' => $this->whenLoaded('business', fn () => $this->business ? ['id' => $this->business->id, 'name' => $this->business->displayName(), 'code' => $this->business->code] : null),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'name' => $this->customer->name] : null),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'invoice_id' => $a->invoice_id,
                'invoice_amount' => $a->invoice?->amount_due,
                'invoice_balance' => $a->invoice?->balance(),
                'invoice_number' => $a->invoice?->number,
                'installment' => $a->installment?->sequence,
                'amount' => $a->amount,
            ])),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'public_url' => $this->receipt_number ? $this->publicUrl() : null,
        ];
    }
}
