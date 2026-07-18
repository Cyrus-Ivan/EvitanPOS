<?php

namespace Tests\Feature\Inventory;

use App\Models\StockSnapshot;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 18 S8 (F-11) — the ledger-is-source-of-truth repair path is reachable:
 * `inventory:reconcile` rebuilds every snapshot from the ledger sum, for
 * databases the app didn't write (restored or hand-edited files).
 */
class ReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_rebuilds_a_drifted_snapshot_from_the_ledger(): void
    {
        $variant = Variant::factory()->create();
        app(StockLedger::class)->record(
            $variant, 5, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        // Simulate out-of-band drift (snapshots are the one mutable stock store).
        StockSnapshot::where('variant_id', $variant->id)->update(['quantity' => 99]);

        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain('Reconciled 1 drifted snapshot(s).')
            ->assertSuccessful();

        $this->assertSame(5, app(StockLedger::class)->currentStock($variant));
    }

    public function test_reconcile_reports_clean_when_nothing_drifted(): void
    {
        $variant = Variant::factory()->create();
        app(StockLedger::class)->record(
            $variant, 3, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain('All snapshots already match the ledger.')
            ->assertSuccessful();
    }
}
