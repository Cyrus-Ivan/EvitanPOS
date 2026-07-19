<?php

namespace Tests\Feature\Boot;

use App\Models\AuditLog;
use App\Models\StockSnapshot;
use App\Models\User;
use App\Models\Variant;
use App\Support\Boot\LaunchProvisioner;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 19 — once-per-launch provisioning for the native app: always migrate,
 * seed the baseline when the database has no users at all, then rebuild stock
 * snapshots from the ledger (finishes a restore).
 */
class LaunchProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_launch_seeds_owner_and_business_defaults_with_an_audit_trail(): void
    {
        $this->assertDatabaseCount('users', 0);

        app(LaunchProvisioner::class)->run();

        $owner = User::query()->where('username', 'owner')->first();
        $this->assertNotNull($owner);
        $this->assertTrue($owner->isOwner());
        $this->assertDatabaseHas('settings', ['key' => 'business_name']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'system.provisioned',
            'user_id' => null,
        ]);
    }

    public function test_a_soft_deleted_user_still_counts_as_an_installed_database(): void
    {
        // A restored backup whose only user was archived must not be treated
        // as a fresh install (re-seeding would resurrect owner/password).
        User::factory()->cashier()->create()->delete();

        app(LaunchProvisioner::class)->run();

        $this->assertNull(User::query()->where('username', 'owner')->first());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'system.provisioned']);
    }

    public function test_running_twice_provisions_only_once(): void
    {
        app(LaunchProvisioner::class)->run();
        app(LaunchProvisioner::class)->run();

        $this->assertSame(1, User::withTrashed()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'system.provisioned')->count());
    }

    public function test_run_repairs_a_drifted_stock_snapshot_from_the_ledger(): void
    {
        $variant = Variant::factory()->create();
        app(StockLedger::class)->record(
            $variant, 5, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        // Out-of-band drift, as a restored or hand-edited database could carry.
        StockSnapshot::where('variant_id', $variant->id)->update(['quantity' => 99]);

        app(LaunchProvisioner::class)->run();

        $this->assertSame(5, app(StockLedger::class)->currentStock($variant));
    }
}
