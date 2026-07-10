<?php

namespace App\Support\Auth;

use App\Enums\LoginEventType;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the login-history lifecycle for the desktop app.
 *
 * NativePHP forces the session driver to `file`, so sessions are stored on disk
 * and survive an unclean exit (window closed, OS shutdown, crash, or force-kill)
 * — the DB `sessions` table is empty in the native runtime. We therefore cannot
 * detect an interrupted session by reconciling that table. Instead, every app
 * run mints a fresh "boot id"; a session stamped with an older id outlived the
 * run that created it and is treated as an unclean exit (see EnforceBootSession).
 */
class SessionManager
{
    /**
     * Cache key holding the current run's boot id. Backed by the shared
     * (database) cache so every NativePHP worker process sees the same value.
     */
    private const BOOT_ID_KEY = 'app_boot_id';

    public function __construct(private readonly LoginHistoryRecorder $recorder) {}

    /**
     * Mint a fresh boot id for this application run. Called on ApplicationBooted,
     * which fires reliably on every launch (unlike the window-close/shutdown
     * events, which race the process teardown and rarely reach PHP in time).
     */
    public function handleBoot(): string
    {
        $bootId = (string) Str::uuid();
        Cache::forever(self::BOOT_ID_KEY, $bootId);

        return $bootId;
    }

    /**
     * The current run's boot id, or null if one has not been minted yet.
     */
    public function currentBootId(): ?string
    {
        return Cache::get(self::BOOT_ID_KEY);
    }

    /**
     * Record a successful login. The desktop app is single-window, so the one
     * session cookie already guarantees a single active user; forcing out a
     * session that lingered from an earlier run is the boot-session guard's job.
     */
    public function handleLogin(User $user): void
    {
        $this->recorder->record($user, LoginEventType::Login);
    }

    /**
     * Graceful, user-initiated logout.
     */
    public function handleLogout(User $user): void
    {
        $this->recorder->record($user, LoginEventType::Logout);
    }

    /**
     * A session that outlived the run it was created in: the previous run ended
     * without a clean logout (window closed, shutdown, crash, or force-kill).
     * All such interruptions are indistinguishable by the next launch, so they
     * share a single event type.
     */
    public function markUncleanExit(User $user): void
    {
        $this->recorder->record(
            $user,
            LoginEventType::ApplicationCrash,
            null,
            'Signed out: the app did not close cleanly (detected on next launch).',
        );
    }

    /**
     * Administratively evict a user (e.g. after an Owner/Admin resets their
     * password) so they must sign in again. Records the event and, defensively,
     * clears any database-backed session rows for them (a no-op under the native
     * file-session driver, but correct should the driver ever be `database`).
     */
    public function forceLogout(User $target, ?string $reason = null): void
    {
        $this->recorder->record(
            $target,
            LoginEventType::ForcedLogout,
            null,
            $reason ?? 'Signed out by an administrator.',
        );

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $target->getKey())
            ->delete();
    }
}
