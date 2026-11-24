<?php

namespace App\Http\Resources;

use App\Domain\Business\Models\BusinessMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BusinessMember */
class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'job_title' => $this->job_title,
            'two_factor_enabled' => (bool) $this->user?->two_factor_enabled,
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
                'phone' => $this->user?->phone,
                'avatar_url' => $this->user?->avatar_url,
            ],
            'joined_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
