<?php

namespace Tests\Unit;

use App\Domain\Business\Enums\Permission;
use App\Domain\Business\Enums\StaffRole;
use PHPUnit\Framework\TestCase;

class StaffRoleTest extends TestCase
{
    public function test_assignment_hierarchy(): void
    {
        $this->assertTrue(StaffRole::Owner->canAssign(StaffRole::Admin));
        $this->assertFalse(StaffRole::Owner->canAssign(StaffRole::Owner));
        $this->assertTrue(StaffRole::Admin->canAssign(StaffRole::Finance));
        $this->assertFalse(StaffRole::Admin->canAssign(StaffRole::Admin));
        $this->assertFalse(StaffRole::Finance->canAssign(StaffRole::Viewer));
    }

    public function test_permissions(): void
    {
        $this->assertTrue(StaffRole::Admin->can(Permission::StaffManage));
        $this->assertFalse(StaffRole::Finance->can(Permission::StaffManage));
        $this->assertTrue(StaffRole::Finance->can(Permission::AuditView));
        $this->assertFalse(StaffRole::Viewer->can(Permission::BusinessUpdate));
    }
}
