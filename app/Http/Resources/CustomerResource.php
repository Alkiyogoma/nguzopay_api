<?php

namespace App\Http\Resources;

use App\Domain\Billing\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'credit_balance' => $this->credit_balance,
            'group' => $this->whenLoaded('group', fn () => $this->group ? ['id' => $this->group->id, 'name' => $this->group->name] : null),
            'account_number' => $this->whenLoaded('accountNumber', fn () => $this->accountNumber?->number),
            'metadata' => $this->metadata,
            'open_invoices_count' => $this->whenCounted('open_invoices_count'),
            'outstanding' => $this->when(isset($this->outstanding), fn () => (int) $this->outstanding),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
