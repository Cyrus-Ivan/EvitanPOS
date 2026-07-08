<?php

namespace Tests\Feature\Auth;

use App\Enums\LoginEventType;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\Auth\SessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SessionReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): SessionManager
    {
        return app(SessionManager::class);
    }

    private function seedSession(string $userId, int $lastActivity): string
    {
        $id = (string) Str::uuid();
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => 'x',
            'last_activity' => $lastActivity,
        ]);

        return $id;
    }

    public function test_reconcile_logs_application_crash_for_an_open_leftover_session()
    {
        $user = User::factory()->create();
        LoginHistory::create([
            'user_id' => $user->id,
            'event_type' => LoginEventType::Login,
            'occurred_at' => now()->subMinutes(30),
        ]);
        $crashedAt = now()->subMinutes(25)->timestamp;
        $sessionId = $this->seedSession($user->id, $crashedAt);

        $this->manager()->reconcile();

        $crash = LoginHistory::where('user_id', $user->id)
            ->where('event_type', LoginEventType::ApplicationCrash)
            ->first();
        $this->assertNotNull($crash);
        $this->assertSame($crashedAt, $crash->occurred_at->timestamp);
        // Stale session cleared.
        $this->assertSame(0, DB::table('sessions')->where('id', $sessionId)->count());

        // Idempotent: a second reconcile does nothing.
        $this->manager()->reconcile();
        $this->assertSame(
            1,
            LoginHistory::where('user_id', $user->id)
                ->where('event_type', LoginEventType::ApplicationCrash)
                ->count()
        );
    }

    public function test_reconcile_ignores_a_cleanly_closed_session()
    {
        $user = User::factory()->create();
        LoginHistory::create([
            'user_id' => $user->id,
            'event_type' => LoginEventType::Login,
            'occurred_at' => now()->subMinutes(30),
        ]);
        LoginHistory::create([
            'user_id' => $user->id,
            'event_type' => LoginEventType::Logout,
            'occurred_at' => now()->subMinutes(20),
        ]);
        // A leftover row with no matching open event (already terminated).
        $this->seedSession($user->id, now()->subMinutes(20)->timestamp);

        $this->manager()->reconcile();

        $this->assertSame(
            0,
            LoginHistory::where('user_id', $user->id)
                ->where('event_type', LoginEventType::ApplicationCrash)
                ->count()
        );
    }

    public function test_window_closed_records_event_clears_session_and_leaves_reconcile_a_noop()
    {
        $user = User::factory()->create();
        LoginHistory::create([
            'user_id' => $user->id,
            'event_type' => LoginEventType::Login,
            'occurred_at' => now()->subMinutes(5),
        ]);
        $this->seedSession($user->id, now()->subMinutes(1)->timestamp);

        $this->manager()->handleWindowClosed();

        $this->assertSame(
            1,
            LoginHistory::where('user_id', $user->id)
                ->where('event_type', LoginEventType::WindowClosed)
                ->count()
        );
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());

        // No crash should be inferred afterwards.
        $this->manager()->reconcile();
        $this->assertSame(
            0,
            LoginHistory::where('user_id', $user->id)
                ->where('event_type', LoginEventType::ApplicationCrash)
                ->count()
        );
    }

    public function test_shutdown_records_power_loss()
    {
        $user = User::factory()->create();
        LoginHistory::create([
            'user_id' => $user->id,
            'event_type' => LoginEventType::Login,
            'occurred_at' => now()->subMinutes(5),
        ]);
        $this->seedSession($user->id, now()->subMinutes(1)->timestamp);

        $this->manager()->handleShutdown();

        $this->assertSame(
            1,
            LoginHistory::where('user_id', $user->id)
                ->where('event_type', LoginEventType::PowerLoss)
                ->count()
        );
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }
}
