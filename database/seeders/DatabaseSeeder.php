<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\Settings\BusinessSettings;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Settings defaults only. No user is seeded: a fresh production install is
     * routed to the initial setup wizard (RequireInitialSetup), which creates
     * the Owner account and business settings itself. Idempotent — safe to
     * re-run.
     *
     * Local runs also get DemoDataSeeder (demo accounts incl. the dev login
     * owner/password, catalog, stock, sales history) so every screen has
     * something to show during development. Elsewhere run it explicitly:
     * `php artisan db:seed --class=DemoDataSeeder`.
     */
    public function run(): void
    {
        // Business-identity defaults (Phase 4) so the first run isn't blank.
        foreach (BusinessSettings::DEFAULTS as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }

        if (app()->environment('local')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
