<?php

namespace App\Support\Boot;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\Artisan;

/**
 * Once-per-launch database provisioning for the native app (Phase 19).
 *
 * The NativePHP runtime only migrates when the app version changes, which
 * misses two cases this covers by always migrating: a same-version relaunch
 * after a database restore, and a restored backup that predates newer
 * migrations (e.g. the Phase 18 immutability triggers). A fresh install has
 * an empty database and no seeding anywhere in the packaged runtime, so the
 * first launch seeds the baseline (Owner account + business defaults) —
 * detected by the absence of any user, archived ones included. The closing
 * reconcile sweep rebuilds stock snapshots from the ledger, which finishes a
 * restore (F-11) and is a cheap no-op otherwise.
 *
 * Runs inside the /_native/api/booted request before the window opens, so all
 * of this completes before the first page load touches the database.
 */
class LaunchProvisioner
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function run(): void
    {
        Artisan::call('migrate', ['--force' => true]);

        if (User::withTrashed()->doesntExist()) {
            // DatabaseSeeder is idempotent and production-safe: Owner account
            // + business-identity defaults; demo data self-gates to local.
            Artisan::call('db:seed', ['--force' => true]);

            $this->audit->record(
                action: 'system.provisioned',
                description: 'First launch: seeded the initial Owner account and business defaults.',
            );
        }

        Artisan::call('inventory:reconcile');
    }
}
