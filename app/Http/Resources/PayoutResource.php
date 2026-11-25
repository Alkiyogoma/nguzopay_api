<?php

namespace App\Http\Resources;

use App\Domain\Payouts\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payout */
class PayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The full account is shown to NGUZO finance (who pay it) and masked everywhere else.
        $full = (bool) $request->user()?->is_platform_admin && $request->is('api/v1/admin/*');

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'kind' => $this->kind,
            'settlement_id' => $this->settlement_id,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'channel' => $this->channel,
            'operator' => $this->operator?->value,
            'operator_label' => $this->operator?->label(),
            'bank_name' => $this->bank_name,
            'recipient_name' => $this->recipient_name,
            'recipient_account' => $full ? $this->recipient_account : $this->maskedAccount(),
            'narration' => $this->narration,
            'status' => $this->status,
            'external_reference' => $this->external_reference,
            'failure_reason' => $this->failure_reason,
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy ? ['id' => $this->requestedBy->id, 'name' => $this->requestedBy->name] : null),
            'processed_by' => $this->whenLoaded('processedBy', fn () => $this->processedBy ? ['id' => $this->processedBy->id, 'name' => $this->processedBy->name] : null),
            'business' => $this->whenLoaded('business', fn () => $this->business ? ['id' => $this->business->id, 'name' => $this->business->displayName(), 'code' => $this->business->code] : null),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
