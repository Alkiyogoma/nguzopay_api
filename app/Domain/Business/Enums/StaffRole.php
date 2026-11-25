<?php

namespace App\Domain\Business\Enums;

enum StaffRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Finance = 'finance';
    case Developer = 'developer';
    case Support = 'support';
    case Viewer = 'viewer';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner, self::Admin => Permission::cases(),
            self::Finance => [Permission::BusinessView, Permission::StaffView, Permission::AuditView,
                Permission::PaymentsView, Permission::PaymentsCollect, Permission::BillingManage, Permission::PayoutsRequest],
            self::Support => [Permission::BusinessView, Permission::StaffView, Permission::PaymentsView, Permission::PaymentsCollect],
            self::Developer => [Permission::BusinessView, Permission::StaffView, Permission::PaymentsView, Permission::ApiKeysManage],
            self::Viewer => [Permission::BusinessView, Permission::StaffView, Permission::PaymentsView],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /** Roles that move money or hold keys. DEVELOPMENT_PLAN §7: 2FA is mandatory for them. */
    public function requiresTwoFactor(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Finance], true);
    }

    /** Owners manage everyone; admins manage everyone below admin. Nobody grants "owner" by invite. */
    public function canAssign(self $role): bool
    {
        return match ($this) {
            self::Owner => $role !== self::Owner,
            self::Admin => ! in_array($role, [self::Owner, self::Admin], true),
            default => false,
        };
    }
}
