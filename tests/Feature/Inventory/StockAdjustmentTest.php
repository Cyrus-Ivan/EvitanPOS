<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryLedger;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private function stockOf(Variant $variant): int
    {
        return app(StockLedger::class)->currentStock($variant);
    }

    public function test_admin_can_add_stock_with_a_signed_delta()
    {
        $variant = Variant::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock.adjust', $variant->id), ['mode' => 'delta', 'value' => 12, 'notes' => 'Restock'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(12, $this->stockOf($variant));
        $this->assertDatabaseHas('inventory_ledger', [
            'variant_id' => $variant->id,
            'quantity' => 12,
            'type' => 'stock_adjustment',
            'reference_type' => 'manual_adjustment',
            'notes' => 'Restock',
        ]);
    }

    public function test_adding_stock_does_not_require_notes()
    {
        $variant = Variant::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock.adjust', $variant->id), ['mode' => 'delta', 'value' => 8])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(8, $this->stockOf($variant));
        $this->assertDatabaseHas('inventory_ledger', ['variant_id' => $variant->id, 'quantity' => 8, 'notes' => null]);
    }

    public function test_set_mode_records_the_delta_between_the_count_and_current_stock()
    {
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        // Seed to 40, then recount to 37 → delta of -3.
        app(StockLedger::class)->record($variant, 40, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        $this->actingAs($admin)
            ->post(route('stock.adjust', $variant->id), ['mode' => 'set', 'value' => 37, 'notes' => 'Recount'])
            ->assertSessionHasNoErrors();

        $this->assertSame(37, $this->stockOf($variant));
        $this->assertDatabaseHas('inventory_ledger', ['variant_id' => $variant->id, 'quantity' => -3]);
    }

    public function test_notes_are_required_when_setting_a_count()
    {
        $variant = Variant::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock.adjust', $variant->id), ['mode' => 'set', 'value' => 5])
            ->assertSessionHasErrors('notes');

        $this->assertSame(0, InventoryLedger::count());
    }

    public function test_notes_are_required_when_removing_stock()
    {
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        app(StockLedger::class)->record($variant, 10, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        $this->actingAs($admin)
            ->post(route('stock.adjust', $variant->id), ['mode' => 'delta', 'value' => -4])
            ->assertSessionHasErrors('notes');

        // Only the seed entry survives; the note-less removal was rejected.
        $this->assertSame(1, InventoryLedger::where('variant_id', $variant->id)->count());
        $this->assertSame(10, $this->stockOf($variant));
    }

    public function test_an_adjustment_below_zero_is_rejected_and_writes_no_ledger_row()
    {
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        app(StockLedger::class)->record($variant, 3, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        $this->actingAs($admin)
            ->post(route('stock.adjust', $variant->id), ['mode' => 'delta', 'value' => -10, 'notes' => 'oops'])
            ->assertSessionHasErrors('value');

        // Only the seed entry survives; the failed adjustment wrote nothing.
        $this->assertSame(1, InventoryLedger::where('variant_id', $variant->id)->count());
        $this->assertSame(3, $this->stockOf($variant));
    }

    public function test_a_no_op_zero_delta_is_rejected()
    {
        $variant = Variant::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock.adjust', $variant->id), ['mode' => 'delta', 'value' => 0, 'notes' => 'nothing'])
            ->assertSessionHasErrors('value');

        $this->assertSame(0, InventoryLedger::count());
    }

    public function test_cashier_cannot_adjust_stock()
    {
        $variant = Variant::factory()->create();

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('stock.adjust', $variant->id), ['mode' => 'delta', 'value' => 5, 'notes' => 'nope'])
            ->assertForbidden();

        $this->assertSame(0, InventoryLedger::count());
    }

    public function test_inventory_index_exposes_current_stock_on_variants()
    {
        $variant = Variant::factory()->create();
        app(StockLedger::class)->record($variant, 7, 'stock_adjustment', null, 'manual_adjustment', null, User::factory()->admin()->create());

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inventory'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('items.data.0.variants.0.current_stock', 7));
    }
}
