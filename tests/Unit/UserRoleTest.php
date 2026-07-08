<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function test_role_levels_are_ordered_owner_admin_cashier()
    {
        $this->assertGreaterThan(UserRole::Admin->level(), UserRole::Owner->level());
        $this->assertGreaterThan(UserRole::Cashier->level(), UserRole::Admin->level());
    }

    public function test_at_least_honours_the_hierarchy()
    {
        $this->assertTrue(UserRole::Owner->atLeast(UserRole::Admin));
        $this->assertTrue(UserRole::Owner->atLeast(UserRole::Owner));
        $this->assertTrue(UserRole::Admin->atLeast(UserRole::Cashier));

        $this->assertFalse(UserRole::Cashier->atLeast(UserRole::Admin));
        $this->assertFalse(UserRole::Admin->atLeast(UserRole::Owner));
    }

    public function test_user_role_predicates()
    {
        $owner = $this->userWithRole(UserRole::Owner);
        $admin = $this->userWithRole(UserRole::Admin);
        $cashier = $this->userWithRole(UserRole::Cashier);

        $this->assertTrue($owner->isOwner());
        $this->assertFalse($owner->isAdmin());

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isOwner());

        $this->assertTrue($cashier->isCashier());
        $this->assertFalse($cashier->isAdmin());
    }

    public function test_has_role_at_least()
    {
        $this->assertTrue($this->userWithRole(UserRole::Owner)->hasRoleAtLeast(UserRole::Admin));
        $this->assertTrue($this->userWithRole(UserRole::Admin)->hasRoleAtLeast(UserRole::Admin));
        $this->assertFalse($this->userWithRole(UserRole::Cashier)->hasRoleAtLeast(UserRole::Admin));
    }

    private function userWithRole(UserRole $role): User
    {
        $user = new User;
        $user->role = $role;

        return $user;
    }
}
