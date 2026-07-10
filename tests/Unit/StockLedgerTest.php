<?php

namespace Tests\Unit;

use App\Exceptions\NegativeInventoryException;
use App\Models\InventoryLedger;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(): StockLedger
    {
        return app(StockLedger::class);
    }

    public function test_recording_a_movement_creates_a_ledger_row_and_updates_the_snapshot()
    {
        $user = User::factory()->owner()->create();
        $variant = Variant::factory()->create();

        $entry = $this->ledger()->record(
            $variant, 10, 'stock_adjustment', null, 'manual_adjustment', null, $user, 'initial'
        );

        $this->assertSame(10, $entry->quantity);
        $this->assertSame(10, $entry->resulting_snapshot);
        $this->assertSame(10, $this->ledger()->currentStock($variant));
        $this->assertDatabaseHas('stock_snapshots', ['variant_id' => $variant->id, 'quantity' => 10]);
    }

    public function test_resulting_snapshot_tracks_the_running_total_across_a_sequence()
    {
        $user = User::factory()->owner()->create();
        $variant = Variant::factory()->create();
        $ledger = $this->ledger();

        $ledger->record($variant, 10, 'stock_adjustment', null, 'manual_adjustment', null, $user);
        $ledger->record($variant, 5, 'stock_adjustment', null, 'manual_adjustment', null, $user);
        $last = $ledger->record($variant, -4, 'sale', 'sale', 'transaction', null, $user);

        $this->assertSame(11, $last->resulting_snapshot);
        $this->assertSame(11, $ledger->currentStock($variant));
        $this->assertSame(3, InventoryLedger::where('variant_id', $variant->id)->count());
    }

    public function test_a_movement_that_would_go_negative_is_rejected_and_writes_nothing()
    {
        $user = User::factory()->owner()->create();
        $variant = Variant::factory()->create();
        $ledger = $this->ledger();

        $ledger->record($variant, 3, 'stock_adjustment', null, 'manual_adjustment', null, $user);

        try {
            $ledger->record($variant, -5, 'stock_adjustment', null, 'manual_adjustment', null, $user);
            $this->fail('Expected NegativeInventoryException.');
        } catch (NegativeInventoryException) {
            // expected
        }

        // The failed write left no row and did not touch the snapshot.
        $this->assertSame(1, InventoryLedger::where('variant_id', $variant->id)->count());
        $this->assertSame(3, $ledger->currentStock($variant));
    }

    public function test_a_defect_writes_a_zero_movement_audit_row_without_changing_stock()
    {
        $user = User::factory()->owner()->create();
        $variant = Variant::factory()->create();
        $ledger = $this->ledger();

        $ledger->record($variant, 8, 'stock_adjustment', null, 'manual_adjustment', null, $user);
        $entry = $ledger->record($variant, 0, 'sale', 'defect', 'return', 'ref-id', $user, 'broken');

        $this->assertSame(0, $entry->quantity);
        $this->assertSame(8, $entry->resulting_snapshot);
        $this->assertSame(8, $ledger->currentStock($variant));
    }

    public function test_reconcile_rebuilds_the_snapshot_from_the_ledger_sum()
    {
        $user = User::factory()->owner()->create();
        $variant = Variant::factory()->create();
        $ledger = $this->ledger();

        $ledger->record($variant, 10, 'stock_adjustment', null, 'manual_adjustment', null, $user);
        $ledger->record($variant, -2, 'sale', 'sale', 'transaction', null, $user);

        // Corrupt the snapshot, then reconcile back to the true ledger sum.
        $variant->stockSnapshot()->update(['quantity' => 999]);

        $this->assertSame(8, $ledger->reconcile($variant));
        $this->assertSame(8, $ledger->currentStock($variant));
    }
}
