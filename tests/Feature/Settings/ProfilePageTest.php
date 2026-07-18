<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 18 S8 (F-15) — the profile page is a read-only identity view.
 * Renaming and self-deletion happen only through the audited User Management
 * flows (Phase 13: names are immutable after creation; users are archived,
 * never self-deleted), so the starter kit's update/destroy endpoints are gone.
 */
class ProfilePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings/profile')
            ->assertOk();
    }

    public function test_profile_can_no_longer_be_updated(): void
    {
        $user = User::factory()->create(['name' => 'Original Name']);

        $this->actingAs($user)
            ->patch('/settings/profile', ['name' => 'New Name', 'username' => 'renamed'])
            ->assertStatus(405);

        $this->assertSame('Original Name', $user->fresh()->name);
    }

    public function test_account_can_no_longer_be_self_deleted(): void
    {
        $user = User::factory()->cashier()->create();

        $this->actingAs($user)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertStatus(405);

        $this->assertNull($user->fresh()->deleted_at);
    }
}
