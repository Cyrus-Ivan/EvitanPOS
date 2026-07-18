<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 18 S5 — "Never delete business records": archived items/variants are
 * hidden from default lists but must remain visible in all historical records.
 *
 * (The matching sweep for archived USERS is parked with finding F-26 — the
 * user relations drop soft-deleted rows today; those tests land with the S8
 * fix.)
 */
class ArchivedVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function sellAndArchive(): array
    {
        $cashier = User::factory()->cashier()->create();
        $variant = Variant::factory()->create(['selling_price' => 100, 'cost' => 40]);

        app(StockLedger::class)->record(
            $variant, 10, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'lines' => [['variant_id' => $variant->id, 'quantity' => 2]],
            'payment_type' => 'cash',
            'cash_received' => 500,
        ])->assertSessionHasNoErrors();

        // Archive both the variant and its parent item after the sale.
        $variant->update(['archived_at' => now()]);
        $variant->item->update(['archived_at' => now()]);

        return [Transaction::with('items')->sole(), $variant->fresh(), $cashier];
    }

    public function test_archived_item_and_variant_still_render_in_sales_detail(): void
    {
        [$transaction, $variant] = $this->sellAndArchive();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('sales.show', $transaction))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('sales/show', false)
                ->where('transaction.items.0.label', $variant->item->name.($variant->name ? ' — '.$variant->name : '')));
    }

    public function test_archived_item_still_counts_in_reports(): void
    {
        [$transaction] = $this->sellAndArchive();

        $date = $transaction->created_at->toDateString();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'daily', 'date' => $date]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports', false)
                ->where('rows.0.gross_sales', '200.00'));
    }

    public function test_archived_variant_is_hidden_from_the_pos_grid_but_history_remains(): void
    {
        [, $variant] = $this->sellAndArchive();

        // Hidden from the sellable grid…
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('pos'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('pos', false)
                ->where('products.total', 0));

        // …but its ledger history (the sale rows) is still browsable.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('stock-history.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('inventory/stock-history', false)
                ->where('entries.total', 2)); // seed adjustment + sale deduction
    }

    public function test_archived_variant_can_still_be_returned_against(): void
    {
        [$transaction] = $this->sellAndArchive();
        $line = $transaction->items->sole();

        // Returns act on the historical sale line — archiving the product must
        // not block the after-sales flow (stock is restored to the archived
        // variant, ready if it is ever restored).
        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('sales.returns.store', $transaction), [
                'type' => 'return',
                'refund_payment_type' => 'cash',
                'lines' => [['transaction_item_id' => $line->id, 'quantity' => 1]],
            ])
            ->assertSessionHasNoErrors();
    }
}
