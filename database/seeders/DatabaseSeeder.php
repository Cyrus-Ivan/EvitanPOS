<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * The baseline every installation needs: the single Owner account and the
     * business-identity settings. Idempotent — safe to re-run.
     *
     * Local runs also get DemoDataSeeder (catalog, stock, sales history) so
     * every screen has something to show during development. Elsewhere run it
     * explicitly: `php artisan db:seed --class=DemoDataSeeder`.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['username' => 'owner'],
            [
                'name' => 'Owner',
                'password' => Hash::make('password'),
                'role' => UserRole::Owner,
                // The default credentials are public knowledge, so a fresh
                // install forces a new password at first login. Local dev gets
                // the flag cleared again by DemoDataSeeder.
                'must_change_password' => true,
            ],
        );

        // Business-identity defaults (Phase 4) so the first run isn't blank.
        $businessDefaults = [
            'business_name' => 'Evitan POS',
            'business_address' => '',
            'tin' => '',
            'receipt_footer' => '',
        ];

        foreach ($businessDefaults as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }

        if (app()->environment('local')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
