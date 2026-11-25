<?php

namespace App\Http\Resources;

use App\Domain\Collections\Models\PaymentIntent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentIntent */
class PaymentIntentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'channel' => $this->channel,
            'operator' => $this->operator?->value,
            'operator_label' => $this->operator?->label(),
            'payer_phone' => $this->payer_phone,
            'description' => $this->description,
            'status' => $this->status->value,
            'failure_reason' => $this->failure_reason,
            'invoice_id' => $this->invoice_id,
            'customer' => $this->whenLoaded('customer', fn () => ['id' => $this->customer->id, 'name' => $this->customer->name]),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? ['id' => $this->invoice->id, 'number' => $this->invoice->number] : null),
            'attempts' => $this->whenLoaded('attempts', fn () => $this->attempts->map(fn ($a) => [
                'id' => $a->id,
                'provider' => $a->provider,
                'provider_reference' => $a->provider_reference,
                'status' => $a->status->value,
                'error_kind' => $a->error_kind,
                'message' => $a->message,
                'created_at' => $a->created_at?->toIso8601String(),
            ])),
            'payment_id' => $this->whenLoaded('payment', fn () => $this->payment?->id),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'succeeded_at' => $this->succeeded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
