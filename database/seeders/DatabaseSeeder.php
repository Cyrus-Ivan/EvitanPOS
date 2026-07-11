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
     * Seed the application's database.
     *
     * Evitan POS requires exactly one Owner. This is a temporary dev seed so the
     * app is usable immediately; proper first-run Owner provisioning is
     * formalized in Phase 2 (Authentication).
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['username' => 'owner'],
            [
                'name' => 'Owner',
                'password' => Hash::make('password'),
                'role' => UserRole::Owner,
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
    }
}
