<?php

namespace App\Domain\Business\Policies;

use App\Domain\Business\Enums\Permission;
use App\Domain\Business\Models\Business;
use App\Domain\Identity\Models\User;

/**
 * Staff permissions inside a business. Platform admins act through the
 * /v1/admin endpoints instead, not by being implicit members.
 */
class BusinessPolicy
{
    public function view(User $user, Business $business): bool
    {
        return $this->has($user, $business, Permission::BusinessView);
    }

    public function update(User $user, Business $business): bool
    {
        return $this->has($user, $business, Permission::BusinessUpdate);
    }

    public function manageKyc(User $user, Business $business): bool
    {
        return $this->has($user, $business, Permission::KycManage);
    }

    public function viewStaff(User $user, Business $business): bool
    {
        return $this->has($user, $business, Permission::StaffView);
    }

    public function manageStaff(User $user, Business $business): bool
    {
        return $this->has($user, $business, Permission::StaffManage);
    }

    public function viewAudit(User $user, Business $business): bool
    {
        return $this->has($user, $business, Permission::AuditView);
    }

    private function has(User $user, Business $business, Permission $permission): bool
    {
        return (bool) $business->roleOf($user)?->can($permission);
    }
}
