<?php

namespace Tests\Feature\Auth;

use App\Enums\LoginEventType;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SingleSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_login_evicts_another_active_session_and_records_forced_logout()
    {
        $other = User::factory()->create();
        // Other user has an open session (logged in, no terminal event).
        LoginHistory::create([
            'user_id' => $other->id,
            'event_type' => LoginEventType::Login,
            'occurred_at' => now()->subMinutes(10),
        ]);
        $this->seedSession($other->id);

        $user = User::factory()->create();
        $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();

        // The other user's session is gone...
        $this->assertSame(0, DB::table('sessions')->where('user_id', $other->id)->count());
        // ...and it was recorded as a forced logout.
        $this->assertSame(
            1,
            LoginHistory::where('user_id', $other->id)
                ->where('event_type', LoginEventType::ForcedLogout)
                ->count()
        );
    }

    public function test_new_login_does_not_force_logout_an_already_closed_session()
    {
        $other = User::factory()->create();
        // Other user already logged out cleanly before this stale row lingered.
        LoginHistory::create([
            'user_id' => $other->id,
            'event_type' => LoginEventType::Login,
            'occurred_at' => now()->subMinutes(20),
        ]);
        LoginHistory::create([
            'user_id' => $other->id,
            'event_type' => LoginEventType::Logout,
            'occurred_at' => now()->subMinutes(15),
        ]);
        $this->seedSession($other->id);

        $user = User::factory()->create();
        $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertSame(
            0,
            LoginHistory::where('user_id', $other->id)
                ->where('event_type', LoginEventType::ForcedLogout)
                ->count()
        );
    }

    private function seedSession(string $userId): void
    {
        DB::table('sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => 'x',
            'last_activity' => now()->subMinutes(5)->timestamp,
        ]);
    }
}
