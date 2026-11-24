<?php

namespace App\Http\Resources;

use App\Domain\Business\Models\StaffInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StaffInvitation */
class InvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'job_title' => $this->job_title,
            'status' => $this->status(),
            'invited_by' => $this->inviter?->name,
            'business' => $this->whenLoaded('business', fn () => [
                'id' => $this->business->id,
                'name' => $this->business->displayName(),
                'logo_url' => (new BusinessResource($this->business))->toArray($request)['logo_url'],
            ]),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
