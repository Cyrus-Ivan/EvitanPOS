<?php

namespace Tests\Feature\Boot;

use App\Models\StockSnapshot;
use App\Models\User;
use App\Models\Variant;
use App\Support\Boot\LaunchProvisioner;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 19 — once-per-launch provisioning for the native app: always migrate,
 * then rebuild stock snapshots from the ledger (finishes a restore). It never
 * seeds anything — a fresh install is routed to the setup wizard instead.
 */
class LaunchProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_never_creates_users_or_audit_rows_on_an_empty_database(): void
    {
        $this->assertDatabaseCount('users', 0);

        app(LaunchProvisioner::class)->run();

        // The setup wizard owns first-run identity; provisioning stays inert.
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('audit_logs', 0);
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
