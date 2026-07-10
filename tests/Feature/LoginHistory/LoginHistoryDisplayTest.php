<?php

namespace Tests\Feature\LoginHistory;

use App\Enums\LoginEventType;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginHistoryDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_sees_only_their_own_events()
    {
        $cashier = User::factory()->cashier()->create();
        $other = User::factory()->cashier()->create();

        LoginHistory::factory()->count(3)->for($cashier)->create();
        LoginHistory::factory()->count(2)->for($other)->create();

        $this->actingAs($cashier)->get(route('login-history'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 3)
                ->where('canViewAll', false)
                ->where('users', [])
            );
    }

    public function test_cashier_cannot_widen_scope_via_the_user_parameter()
    {
        $cashier = User::factory()->cashier()->create();
        $other = User::factory()->cashier()->create();

        LoginHistory::factory()->count(3)->for($cashier)->create();
        LoginHistory::factory()->count(2)->for($other)->create();

        // Even when passing another user's id, the cashier still only sees theirs.
        $this->actingAs($cashier)->get(route('login-history', ['user' => $other->id]))
            ->assertInertia(fn ($page) => $page->has('entries.data', 3));
    }

    public function test_admin_sees_everyone()
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->cashier()->create();

        LoginHistory::factory()->count(2)->for($admin)->create();
        LoginHistory::factory()->count(2)->for($cashier)->create();

        $this->actingAs($admin)->get(route('login-history'))
            ->assertInertia(fn ($page) => $page->has('entries.data', 4)->where('canViewAll', true));
    }

    public function test_admin_can_filter_by_user()
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->cashier()->create();

        LoginHistory::factory()->count(2)->for($admin)->create();
        LoginHistory::factory()->count(3)->for($cashier)->create();

        $this->actingAs($admin)->get(route('login-history', ['user' => $cashier->id]))
            ->assertInertia(fn ($page) => $page->has('entries.data', 3));
    }

    public function test_can_filter_by_event_type()
    {
        $owner = User::factory()->owner()->create();

        LoginHistory::factory()->for($owner)->event(LoginEventType::Login)->count(2)->create();
        LoginHistory::factory()->for($owner)->event(LoginEventType::ApplicationCrash)->count(1)->create();

        $this->actingAs($owner)->get(route('login-history', ['event' => LoginEventType::ApplicationCrash->value]))
            ->assertInertia(fn ($page) => $page->has('entries.data', 1)
                ->where('entries.data.0.event_type', LoginEventType::ApplicationCrash->value));
    }

    public function test_entries_are_ordered_newest_first()
    {
        $owner = User::factory()->owner()->create();

        LoginHistory::factory()->for($owner)->event(LoginEventType::Logout)->create(['occurred_at' => now()->subDays(2)]);
        LoginHistory::factory()->for($owner)->event(LoginEventType::Login)->create(['occurred_at' => now()]);

        $this->actingAs($owner)->get(route('login-history'))
            ->assertInertia(fn ($page) => $page->where('entries.data.0.event_type', LoginEventType::Login->value));
    }
}
