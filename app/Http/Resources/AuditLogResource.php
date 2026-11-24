<?php

namespace App\Http\Resources;

use App\Domain\Platform\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email, 'phone' => $this->user->phone] : null,
            'business' => $this->business ? ['id' => $this->business->id, 'name' => $this->business->displayName()] : null,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'properties' => $this->properties,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
