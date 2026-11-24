<?php

namespace App\Domain\Business\Services;

use App\Domain\Business\Enums\MemberStatus;
use App\Domain\Business\Enums\StaffRole;
use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\StaffInvitation;
use App\Domain\Business\Notifications\StaffInvited;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Jobs\SendSms;
use App\Domain\Platform\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{invitation: StaffInvitation, token: string} */
    public function invite(Business $business, User $inviter, StaffRole $role, ?string $email, ?string $phone, ?string $jobTitle = null): array
    {
        $this->assertCanAssign($business, $inviter, $role);

        $email = $email ? Str::lower($email) : null;

        $existing = User::query()
            ->where(fn ($q) => $q->when($email, fn ($q) => $q->orWhere('email', $email))
                ->when($phone, fn ($q) => $q->orWhere('phone', $phone)))
            ->first();

        if ($existing && $business->members()->where('user_id', $existing->getKey())->exists()) {
            throw ValidationException::withMessages(['email' => 'This person is already on your team.']);
        }

        // One open invitation per address: a new invite replaces the old one.
        $business->invitations()->pending()
            ->where(fn ($q) => $q->when($email, fn ($q) => $q->orWhere('email', $email))
                ->when($phone, fn ($q) => $q->orWhere('phone', $phone)))
            ->update(['revoked_at' => now()]);

        $token = Str::random(48);

        $invitation = $business->invitations()->create([
            'email' => $email,
            'phone' => $phone,
            'role' => $role,
            'job_title' => $jobTitle,
            'token_hash' => StaffInvitation::hashToken($token),
            'invited_by' => $inviter->getKey(),
            'expires_at' => now()->addDays((int) config('nguzopay.invitations.ttl_days')),
        ]);

        $this->deliver($invitation, $token, $inviter);
        $this->audit->record('staff.invited', $invitation, ['role' => $role->value, 'email' => $email, 'phone' => $phone], actor: $inviter, business: $business);

        return ['invitation' => $invitation, 'token' => $token];
    }

    public function findPendingByToken(string $token): ?StaffInvitation
    {
        return StaffInvitation::query()
            ->where('token_hash', StaffInvitation::hashToken($token))
            ->pending()
            ->first();
    }

    public function accept(string $token, User $user): BusinessMember
    {
        $invitation = $this->findPendingByToken($token);

        if (! $invitation) {
            throw ValidationException::withMessages(['token' => 'This invitation is invalid, expired or already used.']);
        }

        // The invite is for an address, so only the verified owner of that address can accept it.
        $matchesEmail = $invitation->email && $user->email === $invitation->email && $user->email_verified_at;
        $matchesPhone = $invitation->phone && $user->phone === $invitation->phone && $user->phone_verified_at;

        if (! $matchesEmail && ! $matchesPhone) {
            throw ValidationException::withMessages(['token' => $invitation->email
                ? 'Sign in with the verified email address this invitation was sent to.'
                : 'Sign in with the verified phone number this invitation was sent to.']);
        }

        $business = $invitation->business;

        if ($business->members()->where('user_id', $user->getKey())->exists()) {
            throw ValidationException::withMessages(['token' => 'You are already on this team.']);
        }

        $member = DB::transaction(function () use ($invitation, $business, $user) {
            $invitation->forceFill(['accepted_at' => now(), 'accepted_by' => $user->getKey()])->save();

            return $business->members()->create([
                'user_id' => $user->getKey(),
                'role' => $invitation->role,
                'job_title' => $invitation->job_title,
                'invited_by' => $invitation->invited_by,
            ]);
        });

        $this->audit->record('staff.joined', $member, ['role' => $member->role->value], actor: $user, business: $business);

        return $member;
    }

    public function revoke(StaffInvitation $invitation, User $actor): void
    {
        if (! $invitation->isPending()) {
            throw ValidationException::withMessages(['invitation' => 'Only a pending invitation can be revoked.']);
        }

        $invitation->forceFill(['revoked_at' => now()])->save();
        $this->audit->record('staff.invitation_revoked', $invitation, actor: $actor, business: $invitation->business);
    }

    public function update(BusinessMember $member, User $actor, array $changes): BusinessMember
    {
        $business = $member->business;
        $this->assertCanManage($business, $actor, $member);

        if (isset($changes['role'])) {
            $role = StaffRole::from($changes['role']);
            $this->assertCanAssign($business, $actor, $role);
            $member->role = $role;
        }

        if (isset($changes['status'])) {
            $member->status = MemberStatus::from($changes['status']);
        }

        if (array_key_exists('job_title', $changes)) {
            $member->job_title = $changes['job_title'];
        }

        $dirty = $member->getDirty();
        $member->save();

        if ($member->status === MemberStatus::Suspended) {
            $this->revokeTokensIfNoActiveMembership($member->user);
        }

        $this->audit->record('staff.updated', $member, ['changes' => array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $dirty)], actor: $actor, business: $business);

        return $member;
    }

    public function remove(BusinessMember $member, User $actor): void
    {
        $this->assertCanManage($member->business, $actor, $member);

        $member->delete();
        $this->audit->record('staff.removed', $member, ['user_id' => $member->user_id, 'role' => $member->role->value], actor: $actor, business: $member->business);
    }

    private function assertCanAssign(Business $business, User $actor, StaffRole $role): void
    {
        $actorRole = $business->roleOf($actor);

        if (! $actorRole || ! $actorRole->canAssign($role)) {
            throw ValidationException::withMessages(['role' => "You cannot give someone the {$role->value} role."]);
        }
    }

    private function assertCanManage(Business $business, User $actor, BusinessMember $member): void
    {
        if ($member->user_id === $actor->getKey()) {
            throw ValidationException::withMessages(['member' => 'You cannot change your own role or access.']);
        }

        if ($member->role === StaffRole::Owner) {
            throw ValidationException::withMessages(['member' => 'The owner cannot be changed or removed.']);
        }

        $this->assertCanAssign($business, $actor, $member->role);
    }

    private function deliver(StaffInvitation $invitation, string $token, User $inviter): void
    {
        $url = config('nguzopay.frontend_url').'/invitations/'.$token;
        $business = $invitation->business;

        if ($invitation->email) {
            Notification::route('mail', $invitation->email)
                ->notify(new StaffInvited($business->displayName(), $inviter->name ?? 'A colleague', $invitation->role, $url));
        }

        if ($invitation->phone) {
            SendSms::dispatch(
                config('nguzopay.sms.default_sender_id'),
                [$invitation->phone],
                "{$business->displayName()} amekualika kujiunga na timu yao kwenye NguzoPay. Fungua: {$url}",
                'staff.invitation',
            );
        }
    }

    /** A suspended member keeps tokens only if they still have another active team. */
    private function revokeTokensIfNoActiveMembership(User $user): void
    {
        if (! $user->memberships()->where('status', MemberStatus::Active)->exists() && ! $user->is_platform_admin) {
            $user->tokens()->delete();
        }
    }
}
