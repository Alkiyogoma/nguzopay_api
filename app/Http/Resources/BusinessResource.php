<?php

namespace App\Http\Resources;

use App\Domain\Business\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Business */
class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $request->user() ? $this->roleOf($request->user()) : null;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'legal_name' => $this->legal_name,
            'trading_name' => $this->trading_name,
            'display_name' => $this->displayName(),
            'registration_authority' => $this->registration_authority?->value,
            'registration_number' => $this->registration_number,
            'tin' => $this->tin,
            'vrn' => $this->vrn,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'region' => $this->region,
            'district' => $this->district,
            'physical_address' => $this->physical_address,
            'postal_address' => $this->postal_address,
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'status' => $this->status->value,
            'editable' => $this->status->isEditable(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'parent_id' => $this->parent_id,
            'sender_id' => $this->whenLoaded('senderIds', fn () => $this->senderIds->first()
                ? new SenderIdResource($this->senderIds->first()) : null),
            'my_role' => $role?->value,
            'my_permissions' => $role ? array_map(fn ($p) => $p->value, $role->permissions()) : [],
            $this->mergeWhen($request->routeIs('v1.admin.*'), fn () => [
                'recommendation' => $this->recommendation,
                'recommendation_notes' => $this->recommendation_notes,
                'recommended_at' => $this->recommended_at?->toIso8601String(),
                'recommended_by' => $this->recommendedBy ? ['id' => $this->recommendedBy->id, 'name' => $this->recommendedBy->name] : null,
                'decided_by' => $this->decidedBy ? ['id' => $this->decidedBy->id, 'name' => $this->decidedBy->name] : null,
                'owner' => $this->owner?->user ? ['id' => $this->owner->user->id, 'name' => $this->owner->user->name, 'email' => $this->owner->user->email, 'phone' => $this->owner->user->phone] : null,
                'members_count' => $this->members()->count(),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
