<?php

namespace Tests\Feature\Pos;

use App\Models\InventoryLedger;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a sellable variant and seed its on-hand stock through the ledger so
     * the snapshot and ledger stay consistent (the app's single write path).
     */
    private function sellable(float $price, ?float $cost, int $stock): Variant
    {
        $variant = Variant::factory()->create(['selling_price' => $price, 'cost' => $cost]);

        if ($stock > 0) {
            app(StockLedger::class)->record(
                $variant, $stock, 'stock_adjustment', null, 'manual_adjustment', null,
                User::factory()->admin()->create(),
            );
        }

        return $variant;
    }

    private function stockOf(Variant $variant): int
    {
        return app(StockLedger::class)->currentStock($variant);
    }

    public function test_checkout_creates_the_transaction_its_items_and_deducts_stock()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 10);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => 3]],
                'payment_type' => 'cash',
                'cash_received' => 500,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $transaction = Transaction::sole();
        $this->assertSame('300.00', $transaction->total);
        $this->assertSame('500.00', $transaction->cash_received);
        $this->assertSame('200.00', $transaction->change);
        $this->assertSame('cash', $transaction->payment_type);

        $item = TransactionItem::sole();
        $this->assertSame($variant->id, $item->variant_id);
        $this->assertSame(3, $item->quantity);
        $this->assertSame('sale', $item->line_type);
        $this->assertSame('300.00', $item->total);

        // Stock deducted and a matching sale ledger row appended.
        $this->assertSame(7, $this->stockOf($variant));
        $this->assertDatabaseHas('inventory_ledger', [
            'variant_id' => $variant->id,
            'quantity' => -3,
            'type' => 'sale',
            'sale_type' => 'sale',
            'reference_type' => 'transaction',
            'reference_id' => $transaction->id,
        ]);
    }

    public function test_overselling_is_rejected_and_nothing_persists()
    {
        $variant = $this->sellable(price: 50, cost: 20, stock: 3);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => 5]],
                'payment_type' => 'cash',
                'cash_received' => 1000,
            ])
            ->assertSessionHasErrors('lines');

        // Atomic rollback: no transaction, no items, no sale ledger row, stock intact.
        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, TransactionItem::count());
        $this->assertSame(0, InventoryLedger::where('type', 'sale')->count());
        $this->assertSame(3, $this->stockOf($variant));
    }

    public function test_a_variant_of_an_archived_item_cannot_be_sold()
    {
        // Archiving an item does not cascade to its variants, so the variant is
        // still active on its own; checkout must reject it as unavailable.
        $variant = $this->sellable(price: 100, cost: 40, stock: 5);
        $variant->item->update(['archived_at' => now()]);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
                'payment_type' => 'online',
            ])
            ->assertSessionHasErrors('lines');

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, TransactionItem::count());
        $this->assertSame(0, InventoryLedger::where('type', 'sale')->count());
        $this->assertSame(5, $this->stockOf($variant));
    }

    public function test_price_and_cost_are_snapshotted_from_the_server_not_the_client()
    {
        $variant = $this->sellable(price: 150, cost: 60, stock: 5);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                // Client attempts to spoof price/cost/total — must be ignored.
                'lines' => [['variant_id' => $variant->id, 'quantity' => 1, 'selling_price' => 1, 'cost' => 1, 'total' => 1]],
                'payment_type' => 'online',
            ])
            ->assertSessionHasNoErrors();

        $item = TransactionItem::sole();
        $this->assertSame('150.00', $item->selling_price);
        $this->assertSame('60.00', $item->cost);
        $this->assertSame('150.00', $item->total);
    }

    public function test_cost_is_never_present_in_the_grid_for_a_cashier()
    {
        $this->sellable(price: 20, cost: 8, stock: 5);

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('pos'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('products.data.0.cost'));
    }

    public function test_amount_discount_is_capped_at_the_subtotal()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 5);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => 2]], // subtotal 200
                'payment_type' => 'online',
                'discount_type' => 'amount',
                'discount' => 500, // absurd — capped to 200
            ])
            ->assertSessionHasNoErrors();

        $transaction = Transaction::sole();
        $this->assertSame('0.00', $transaction->total);
        $this->assertSame('amount', $transaction->discount_type);
        // The stored discount is the effective (capped) amount, not the raw 500.
        $this->assertSame('200.00', $transaction->discount);
    }

    public function test_percentage_discount_applies_and_is_reflected_in_the_total()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 5);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => 2]], // subtotal 200
                'payment_type' => 'online',
                'discount_type' => 'percentage',
                'discount' => 10, // 20 off
            ])
            ->assertSessionHasNoErrors();

        $transaction = Transaction::sole();
        $this->assertSame('180.00', $transaction->total);
        $this->assertSame('percentage', $transaction->discount_type);
    }

    public function test_percentage_discount_over_100_is_rejected()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 5);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
                'payment_type' => 'online',
                'discount_type' => 'percentage',
                'discount' => 150,
            ])
            ->assertSessionHasErrors('discount');

        $this->assertSame(0, Transaction::count());
    }

    public function test_cash_received_below_the_total_is_rejected()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 5);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => 2]], // total 200
                'payment_type' => 'cash',
                'cash_received' => 150,
            ])
            ->assertSessionHasErrors('cash_received');

        $this->assertSame(0, Transaction::count());
        $this->assertSame(5, $this->stockOf($variant));
    }

    public function test_cash_is_required_for_a_cash_sale()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 5);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
                'payment_type' => 'cash',
            ])
            ->assertSessionHasErrors('cash_received');
    }

    public function test_a_non_cash_sale_leaves_cash_and_change_null()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 5);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
                'payment_type' => 'online',
            ])
            ->assertSessionHasNoErrors();

        $transaction = Transaction::sole();
        $this->assertNull($transaction->cash_received);
        $this->assertNull($transaction->change);
    }

    public function test_a_comment_is_stored_on_the_transaction()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 5);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
                'payment_type' => 'online',
                'comment' => '  Held for pickup  ',
            ])
            ->assertSessionHasNoErrors();

        // Stored trimmed.
        $this->assertSame('Held for pickup', Transaction::sole()->comment);
    }

    public function test_a_blank_comment_is_stored_as_null()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 5);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
                'payment_type' => 'online',
                'comment' => '   ',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(Transaction::sole()->comment);
    }

    public function test_an_overlong_comment_is_rejected()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 5);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
                'payment_type' => 'online',
                'comment' => str_repeat('a', 501),
            ])
            ->assertSessionHasErrors('comment');

        $this->assertSame(0, Transaction::count());
    }

    public function test_duplicate_lines_for_one_variant_are_merged()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 10);

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('pos.checkout'), [
                'lines' => [
                    ['variant_id' => $variant->id, 'quantity' => 2],
                    ['variant_id' => $variant->id, 'quantity' => 3],
                ],
                'payment_type' => 'online',
            ])
            ->assertSessionHasNoErrors();

        // One item row (quantity 5), one deduction — not two.
        $item = TransactionItem::sole();
        $this->assertSame(5, $item->quantity);
        $this->assertSame(1, InventoryLedger::where('type', 'sale')->count());
        $this->assertSame(5, $this->stockOf($variant));
    }
}
