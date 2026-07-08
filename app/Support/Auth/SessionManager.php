<?php

namespace App\Support\Auth;

use App\Enums\LoginEventType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Enforces "one authenticated user at a time" and captures the terminal
 * login-history events (logout / window closed / power loss / crash) by
 * reconciling the DB-backed `sessions` table against `login_history`.
 *
 * A session is considered cleanly closed when its `sessions` row is deleted.
 * Every graceful terminal path here deletes the row, so a row still present at
 * next launch means the prior run died without a terminal event (a crash).
 */
class SessionManager
{
    public function __construct(private readonly LoginHistoryRecorder $recorder) {}

    /**
     * On successful login, evict every other session (there may be only one
     * authenticated user at a time). Any evicted session that still belonged to
     * an "open" user is a forced logout for that user.
     */
    public function handleLogin(User $user, string $currentSessionId): void
    {
        $others = DB::table($this->table())
            ->where('id', '!=', $currentSessionId)
            ->whereNotNull('user_id')
            ->get();

        foreach ($others as $row) {
            if ($this->latestEventFor($row->user_id)?->isSessionOpen() === true) {
                if ($evicted = User::withTrashed()->find($row->user_id)) {
                    $this->recorder->record(
                        $evicted,
                        LoginEventType::ForcedLogout,
                        Carbon::createFromTimestamp($row->last_activity),
                        'Evicted by a new login (single active session).',
                    );
                }
            }
        }

        DB::table($this->table())->where('id', '!=', $currentSessionId)->delete();

        $this->recorder->record($user, LoginEventType::Login);
    }

    /**
     * Graceful, user-initiated logout.
     */
    public function handleLogout(User $user): void
    {
        $this->recorder->record($user, LoginEventType::Logout);

        DB::table($this->table())->where('user_id', $user->getKey())->delete();
    }

    /**
     * The desktop window was closed (NativePHP WindowClosed event).
     */
    public function handleWindowClosed(): void
    {
        $this->terminateActiveSessions(LoginEventType::WindowClosed);
    }

    /**
     * The OS is shutting down while the app runs (NativePHP PowerMonitor
     * Shutdown event) — the closest match in the fixed vocabulary is power loss.
     */
    public function handleShutdown(): void
    {
        $this->terminateActiveSessions(LoginEventType::PowerLoss);
    }

    /**
     * Runs on every launch (NativePHP ApplicationBooted). Any leftover session
     * whose user is still "open" means the previous run ended without a terminal
     * event — record an application crash, back-dated to the session's last
     * activity, then clear the stale rows. Idempotent.
     */
    public function reconcile(): void
    {
        $leftovers = DB::table($this->table())->whereNotNull('user_id')->get();

        foreach ($leftovers as $row) {
            if ($this->latestEventFor($row->user_id)?->isSessionOpen() === true) {
                if ($user = User::withTrashed()->find($row->user_id)) {
                    $this->recorder->record(
                        $user,
                        LoginEventType::ApplicationCrash,
                        Carbon::createFromTimestamp($row->last_activity),
                        'Unclean shutdown detected on next startup.',
                    );
                }
            }

            DB::table($this->table())->where('id', $row->id)->delete();
        }
    }

    /**
     * Record the given terminal event for every currently-active user, then
     * clear their sessions so the next launch sees a clean state.
     */
    private function terminateActiveSessions(LoginEventType $type): void
    {
        $rows = DB::table($this->table())->whereNotNull('user_id')->get();

        $seen = [];
        foreach ($rows as $row) {
            if (in_array($row->user_id, $seen, true)) {
                continue;
            }
            $seen[] = $row->user_id;

            if ($user = User::withTrashed()->find($row->user_id)) {
                $this->recorder->record($user, $type);
            }
        }

        DB::table($this->table())->whereNotNull('user_id')->delete();
    }

    /**
     * The most recently recorded login-history event for a user, or null.
     */
    private function latestEventFor(string $userId): ?LoginEventType
    {
        $event = DB::table('login_history')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('event_type');

        return $event === null ? null : LoginEventType::from($event);
    }

    private function table(): string
    {
        return config('session.table', 'sessions');
    }
}
