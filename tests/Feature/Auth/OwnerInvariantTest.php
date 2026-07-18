<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Exceptions\CannotDeleteOwnerException;
use App\Exceptions\MultipleOwnersException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_owner_cannot_be_created_at_the_model_layer()
    {
        User::factory()->owner()->create();

        $this->expectException(MultipleOwnersException::class);

        User::factory()->owner()->create();
    }

    public function test_an_existing_user_cannot_be_promoted_to_a_second_owner()
    {
        User::factory()->owner()->create();
        $admin = User::factory()->admin()->create();

        $this->expectException(MultipleOwnersException::class);

        $admin->update(['role' => UserRole::Owner]);
    }

    public function test_the_owner_can_still_save_itself()
    {
        $owner = User::factory()->owner()->create();

        // Updating the sole Owner's own attributes must not trip the guard.
        $owner->update(['name' => 'Renamed Owner']);

        $this->assertSame('Renamed Owner', $owner->fresh()->name);
        $this->assertSame(1, User::where('role', UserRole::Owner->value)->count());
    }

    public function test_owner_cannot_be_deleted_at_the_model_layer()
    {
        $owner = User::factory()->owner()->create();

        $this->expectException(CannotDeleteOwnerException::class);

        $owner->delete();
    }

    public function test_non_owner_can_be_deleted()
    {
        $cashier = User::factory()->create();

        $cashier->delete();

        $this->assertSoftDeleted($cashier);
    }

    public function test_owner_cannot_delete_their_account_via_settings()
    {
        // Self-deletion was removed for every role in Phase 18 (F-15) — the
        // starter kit's destroy endpoint no longer exists at all, which
        // enforces this invariant even more strictly than the old Owner guard.
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->from('/settings/profile')
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertStatus(405);

        $this->assertAuthenticated();
        $this->assertNotNull($owner->fresh());
    }
}
