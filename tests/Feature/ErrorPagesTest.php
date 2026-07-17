<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The exception handler in bootstrap/app.php maps 403/404/500/503 to the
 * styled Inertia error page when debug is off (NativePHP ships production
 * builds with APP_DEBUG=false), and always turns a 419 stale-token response
 * into a redirect-back with an error toast so the desktop app never
 * dead-ends on a raw framework error page.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_404_renders_the_styled_error_page_when_debug_is_off()
    {
        config(['app.debug' => false]);

        $this->actingAs(User::factory()->cashier()->create())
            ->get('/definitely-missing-page')
            ->assertNotFound()
            ->assertInertia(
                fn ($page) => $page
                    ->component('error', false)
                    ->where('status', 404)
            );
    }

    public function test_404_for_a_guest_renders_the_error_page_without_auth_context()
    {
        config(['app.debug' => false]);

        // A 404 fires during route matching, before any middleware shares
        // Inertia props — the error page must render standalone.
        $this->get('/definitely-missing-page')
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('error', false));
    }

    public function test_403_keeps_its_status_code_and_renders_the_error_page_when_debug_is_off()
    {
        config(['app.debug' => false]);

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('users'))
            ->assertForbidden()
            ->assertInertia(
                fn ($page) => $page
                    ->component('error', false)
                    ->where('status', 403)
            );
    }

    public function test_500_renders_the_styled_error_page_when_debug_is_off()
    {
        config(['app.debug' => false]);

        Route::middleware('web')->get('/_test-boom', fn () => abort(500));

        $this->actingAs(User::factory()->cashier()->create())
            ->get('/_test-boom')
            ->assertInternalServerError()
            ->assertInertia(
                fn ($page) => $page
                    ->component('error', false)
                    ->where('status', 500)
            );
    }

    public function test_404_stays_on_the_framework_page_when_debug_is_on()
    {
        config(['app.debug' => true]);

        $this->actingAs(User::factory()->cashier()->create())
            ->get('/definitely-missing-page')
            ->assertNotFound()
            ->assertHeaderMissing('X-Inertia');
    }

    public function test_419_redirects_back_with_an_error_toast()
    {
        Route::middleware('web')->post('/_test-expired', fn () => abort(419));

        $this->actingAs(User::factory()->cashier()->create())
            ->from(route('pos'))
            ->post('/_test-expired')
            ->assertRedirect(route('pos'))
            ->assertSessionHas('toast', [
                'type' => 'error',
                'message' => 'Your session expired. Please try again.',
            ]);
    }

    public function test_json_requests_keep_their_json_error_shape()
    {
        config(['app.debug' => false]);

        $this->actingAs(User::factory()->cashier()->create())
            ->getJson('/definitely-missing-page')
            ->assertNotFound()
            ->assertHeaderMissing('X-Inertia')
            ->assertJsonStructure(['message']);
    }
}
