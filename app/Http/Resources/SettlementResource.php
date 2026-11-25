<?php

namespace App\Http\Resources;

use App\Domain\Collections\Models\Settlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Settlement */
class SettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'settlement_date' => $this->settlement_date->toDateString(),
            'cutoff_at' => $this->cutoff_at->toIso8601String(),
            'payments_count' => $this->payments_count,
            'gross_amount' => $this->gross_amount,
            'provider_fees' => $this->provider_fees,
            'platform_fees' => $this->platform_fees,
            'net_amount' => $this->net_amount,
            'status' => $this->status,
            'payout_reference' => $this->payout_reference,
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
