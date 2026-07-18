<?php

namespace Tests\Feature\Pos;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 18 S5 — the gapless-serial invariant (CLAUDE.md: a continuous,
 * zero-padded running serial with no gaps across committed sales).
 *
 * A failed checkout must never consume a serial: the number is derived and
 * claimed inside the same DB transaction that persists the sale, so any
 * rollback releases it. These tests pin that behaviour for the failure modes a
 * cashier can actually hit. (The concurrent-collision retry path can only
 * succeed once the generator is MAX-based — see finding F-09; that test lands
 * with the S8 fix.)
 */
class TransactionNumberIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function sellable(float $price, int $stock): Variant
    {
        $variant = Variant::factory()->create(['selling_price' => $price]);

        if ($stock > 0) {
            app(StockLedger::class)->record(
                $variant, $stock, 'stock_adjustment', null, 'manual_adjustment', null,
                User::factory()->admin()->create(),
            );
        }

        return $variant;
    }

    /** @param array<string, mixed> $overrides */
    private function checkout(User $user, Variant $variant, int $quantity, array $overrides = [])
    {
        return $this->actingAs($user)->post(route('pos.checkout'), array_merge([
            'lines' => [['variant_id' => $variant->id, 'quantity' => $quantity]],
            'payment_type' => 'cash',
            'cash_received' => 100000,
        ], $overrides));
    }

    public function test_serials_run_sequentially_and_zero_padded(): void
    {
        $cashier = User::factory()->cashier()->create();
        $variant = $this->sellable(price: 10, stock: 10);

        foreach (range(1, 3) as $i) {
            $this->checkout($cashier, $variant, 1)->assertSessionHasNoErrors();
        }

        $numbers = Transaction::query()->orderBy('created_at')->pluck('transaction_number')->all();

        $this->assertSame(['0000000001', '0000000002', '0000000003'], $numbers);
    }

    public function test_oversell_failure_leaves_no_gap(): void
    {
        $cashier = User::factory()->cashier()->create();
        $variant = $this->sellable(price: 10, stock: 5);

        $this->checkout($cashier, $variant, 2)->assertSessionHasNoErrors(); // 0000000001

        // More than remains on hand: the whole checkout rolls back.
        $this->checkout($cashier, $variant, 99)->assertSessionHasErrors('lines');

        $this->assertSame(1, Transaction::count());

        // The rolled-back attempt released its serial: the next sale is #2, not #3.
        $this->checkout($cashier, $variant, 1)->assertSessionHasNoErrors();

        $this->assertSame(
            ['0000000001', '0000000002'],
            Transaction::query()->orderBy('created_at')->pluck('transaction_number')->all(),
        );
    }

    public function test_insufficient_cash_failure_leaves_no_gap(): void
    {
        $cashier = User::factory()->cashier()->create();
        $variant = $this->sellable(price: 100, stock: 10);

        $this->checkout($cashier, $variant, 1)->assertSessionHasNoErrors(); // 0000000001

        $this->checkout($cashier, $variant, 1, ['cash_received' => 50])
            ->assertSessionHasErrors('cash_received');

        $this->checkout($cashier, $variant, 1)->assertSessionHasNoErrors();

        $this->assertSame(
            ['0000000001', '0000000002'],
            Transaction::query()->orderBy('created_at')->pluck('transaction_number')->all(),
        );
    }

    public function test_failed_checkout_rolls_back_stock_and_items_with_the_serial(): void
    {
        $cashier = User::factory()->cashier()->create();
        $good = $this->sellable(price: 10, stock: 10);
        $scarce = $this->sellable(price: 10, stock: 1);

        // A mid-cart failure (second line oversells) must roll back the first
        // line's deduction and the transaction shell along with the serial.
        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'lines' => [
                ['variant_id' => $good->id, 'quantity' => 3],
                ['variant_id' => $scarce->id, 'quantity' => 5],
            ],
            'payment_type' => 'cash',
            'cash_received' => 1000,
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, Transaction::count());
        $ledger = app(StockLedger::class);
        $this->assertSame(10, $ledger->currentStock($good));
        $this->assertSame(1, $ledger->currentStock($scarce));
    }
}
