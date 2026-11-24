<?php

namespace App\Http\Resources;

use App\Domain\Business\Enums\MemberStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isSelf = $request->user()?->is($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email !== null && $this->email_verified_at !== null,
            'phone' => $this->phone,
            'phone_verified' => $this->hasVerifiedPhone(),
            'avatar_url' => $this->avatar_url,
            $this->mergeWhen($isSelf || $request->user()?->is_platform_admin, fn () => [
                'has_password' => $this->hasPassword(),
                'two_factor_enabled' => $this->two_factor_enabled,
                'is_platform_admin' => $this->is_platform_admin,
                'linked_accounts' => $this->socialAccounts->map(fn ($a) => [
                    'provider' => $a->provider->value,
                    'email' => $a->email,
                    'linked_at' => $a->created_at?->toIso8601String(),
                ])->values(),
                'businesses' => $this->memberships()
                    ->with('business')
                    ->where('status', MemberStatus::Active)
                    ->get()
                    ->filter(fn ($m) => $m->business !== null)
                    ->map(fn ($m) => [
                        'id' => $m->business->id,
                        'name' => $m->business->displayName(),
                        'code' => $m->business->code,
                        'status' => $m->business->status->value,
                        'role' => $m->role->value,
                    ])->values(),
                'security' => (function () {
                    // DEVELOPMENT_PLAN §7: 2FA is mandatory for owner/admin/finance roles and NGUZO admins.
                    $required = $this->is_platform_admin || $this->memberships
                        ->contains(fn ($m) => $m->status === MemberStatus::Active && $m->role->requiresTwoFactor());

                    return [
                        'two_factor_required' => $required,
                        'two_factor_recommended' => $required && ! $this->two_factor_enabled,
                    ];
                })(),
                'last_login_at' => $this->last_login_at?->toIso8601String(),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
