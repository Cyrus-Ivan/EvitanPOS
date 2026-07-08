<?php

namespace Tests\Feature\Auth;

use App\Exceptions\CannotDeleteOwnerException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerInvariantTest extends TestCase
{
    use RefreshDatabase;

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
        $owner = User::factory()->owner()->create();

        $response = $this
            ->actingAs($owner)
            ->from('/settings/profile')
            ->delete('/settings/profile', ['password' => 'password']);

        $response->assertRedirect('/settings/profile');
        $this->assertAuthenticated();
        $this->assertNotNull($owner->fresh());
    }
}
