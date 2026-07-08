<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every Phase 0 navigation section. These are placeholder pages for now;
     * role-based authorization is added in Phase 3.
     *
     * @return array<string, array{string}>
     */
    public static function sectionRoutes(): array
    {
        return [
            'pos' => ['pos'],
            'sales' => ['sales'],
            'inventory' => ['inventory'],
            'reports' => ['reports'],
            'users' => ['users'],
            'login-history' => ['login-history'],
            'audit-logs' => ['audit-logs'],
            'business-settings' => ['business-settings'],
            'backup' => ['backup'],
        ];
    }

    #[DataProvider('sectionRoutes')]
    public function test_guests_are_redirected_to_login(string $routeName)
    {
        $this->get(route($routeName))->assertRedirect(route('login'));
    }

    #[DataProvider('sectionRoutes')]
    public function test_authenticated_users_can_visit_each_section(string $routeName)
    {
        $this->actingAs(User::factory()->create());

        $this->get(route($routeName))->assertOk();
    }
}
