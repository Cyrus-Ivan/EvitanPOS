<?php

namespace Tests\Feature\Auth;

use App\Enums\LoginEventType;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\Auth\SessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The boot-session guard is how "unclean exit → forced logout" is enforced:
 * NativePHP keeps sessions in files that survive a window close / shutdown /
 * crash, so on the next launch a carried-over session must be signed out. Each
 * run mints a fresh boot id; a session stamped with a stale id is forced out.
 */
class BootSessionGuardTest extends TestCase
{
    use RefreshDatabase;

    private const BOOT_KEY = 'app_boot_id';

    public function test_a_session_from_a_previous_run_is_forced_out_and_recorded()
    {
        Cache::forever(self::BOOT_KEY, 'current-run');
        $user = User::factory()->create();

        // The session was stamped during an earlier run that ended uncleanly.
        $response = $this->actingAs($user)
            ->withSession(['boot_id' => 'previous-run'])
            ->get(route('pos'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');
        $this->assertGuest();
        $this->assertSame(
            1,
            LoginHistory::where('user_id', $user->id)
                ->where('event_type', LoginEventType::ApplicationCrash)
                ->count()
        );
    }

    public function test_a_session_from_the_current_run_passes_through()
    {
        Cache::forever(self::BOOT_KEY, 'current-run');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['boot_id' => 'current-run'])
            ->get(route('pos'))
            ->assertOk();

        $this->assertAuthenticated();
        $this->assertSame(0, LoginHistory::where('user_id', $user->id)->count());
    }

    public function test_guard_fails_open_when_no_boot_id_has_been_minted()
    {
        // No boot id in the cache (e.g. outside the native runtime / in tests).
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['boot_id' => 'anything'])
            ->get(route('pos'))
            ->assertOk();

        $this->assertAuthenticated();
        $this->assertSame(0, LoginHistory::where('user_id', $user->id)->count());
    }

    public function test_login_stamps_the_current_boot_id_so_the_next_request_is_not_forced_out()
    {
        app(SessionManager::class)->handleBoot();
        $user = User::factory()->create();

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertRedirect(route('pos', absolute: false));

        // The session cookie persists across requests in a test; a follow-up
        // request must not be treated as a stale-boot session.
        $this->get(route('pos'))->assertOk();

        $this->assertAuthenticated();
        $this->assertSame(
            0,
            LoginHistory::where('user_id', $user->id)
                ->where('event_type', LoginEventType::ApplicationCrash)
                ->count()
        );
    }
}
