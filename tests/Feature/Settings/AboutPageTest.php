<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AboutPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_authenticated_user_can_view_the_about_page_with_the_release_version(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('about'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/about', false)
                ->where('version', config('nativephp.version'))
                ->where('appName', config('app.name'))
                ->has('copyright'));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        // A user must exist — on a fresh install the setup wizard answers.
        User::factory()->cashier()->create();

        $this->get(route('about'))->assertRedirect(route('login'));
    }
}
