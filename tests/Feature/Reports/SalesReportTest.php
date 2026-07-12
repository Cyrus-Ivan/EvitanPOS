<?php

namespace Tests\Feature\Reports;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Sales\ProcessSaleReturn;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reports (Phase 12). Every figure is recomputed from the append-only source
 * tables as net = gross − returns − defects; after-sales events are attributed to
 * the period they OCCURRED in (event date). Cost/COGS/profit are Owner-only.
 */
class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private function sellable(float $price, ?float $cost, int $stock): Variant
    {
        $variant = Variant::factory()->create(['selling_price' => $price, 'cost' => $cost]);

        app(StockLedger::class)->record(
            $variant, $stock, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        return $variant;
    }

    /** Ring up a sale through the real checkout and return the resulting transaction. */
    private function sell(User $cashier, Variant $variant, int $quantity): Transaction
    {
        $this->actingAs($cashier)
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => $quantity]],
                'payment_type' => 'cashless',
                'cash_received' => null,
            ])
            ->assertSessionHasNoErrors();

        return Transaction::query()->orderByDesc('transaction_number')->firstOrFail();
    }

    public function test_daily_report_buckets_sales_by_day()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $cashier = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-03-10 12:00'));
        $this->sell($cashier, $variant, 2); // gross 200

        $this->travelTo(Carbon::parse('2026-03-11 12:00'));
        $this->sell($cashier, $variant, 1); // gross 100

        // Reports are Admin+; a cashier rings up sales but an admin views them.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'daily', 'date_from' => '2026-03-01', 'date_to' => '2026-03-31']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports', false)
                ->where('period', 'daily')
                ->has('rows', 2)
                ->where('rows.0.bucket', '2026-03-10')
                ->where('rows.0.transactions', 1)
                ->where('rows.0.gross_sales', '200.00')
                ->where('rows.0.net_sales', '200.00')
                ->where('rows.1.bucket', '2026-03-11')
                ->where('rows.1.net_sales', '100.00')
                ->where('totals.transactions', 2)
                ->where('totals.gross_sales', '300.00')
                ->where('totals.net_sales', '300.00'));
    }

    public function test_returns_reduce_net_on_the_day_they_occur()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $cashier = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-03-10 12:00'));
        $transaction = $this->sell($cashier, $variant, 2); // gross 200

        // Return one unit two days later — it must land on 03-12, not 03-10.
        $this->travelTo(Carbon::parse('2026-03-12 12:00'));
        app(ProcessSaleReturn::class)->process(
            $transaction,
            'return',
            [['transaction_item_id' => $transaction->items()->first()->id, 'quantity' => 1]],
            null,
            'cashless',
            $cashier,
        );

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'daily', 'date_from' => '2026-03-01', 'date_to' => '2026-03-31']))
            ->assertInertia(fn ($page) => $page
                ->has('rows', 2)
                // Sale day is untouched by the later return.
                ->where('rows.0.bucket', '2026-03-10')
                ->where('rows.0.gross_sales', '200.00')
                ->where('rows.0.returns', '0.00')
                ->where('rows.0.net_sales', '200.00')
                // Return day: no sales, a 100 refund, net goes negative.
                ->where('rows.1.bucket', '2026-03-12')
                ->where('rows.1.gross_sales', '0.00')
                ->where('rows.1.returns', '100.00')
                ->where('rows.1.net_sales', '-100.00')
                // Period net = 200 − 100.
                ->where('totals.returns', '100.00')
                ->where('totals.net_sales', '100.00'));
    }

    public function test_defects_reduce_net_sales()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $cashier = User::factory()->cashier()->create();
        $admin = User::factory()->admin()->create();

        $this->travelTo(Carbon::parse('2026-03-10 12:00'));
        $transaction = $this->sell($cashier, $variant, 2); // gross 200

        // Defect (Admin+) one unit the same day.
        app(ProcessSaleReturn::class)->process(
            $transaction,
            'defect',
            [['transaction_item_id' => $transaction->items()->first()->id, 'quantity' => 1]],
            'damaged',
            null,
            $admin,
        );

        $this->actingAs($admin)
            ->get(route('reports', ['period' => 'daily', 'date_from' => '2026-03-01', 'date_to' => '2026-03-31']))
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.gross_sales', '200.00')
                ->where('rows.0.defects', '100.00')
                ->where('rows.0.net_sales', '100.00'));
    }

    public function test_monthly_report_buckets_sales_by_month()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $cashier = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-01-15 12:00'));
        $this->sell($cashier, $variant, 1); // gross 100

        $this->travelTo(Carbon::parse('2026-02-20 12:00'));
        $this->sell($cashier, $variant, 2); // gross 200

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'monthly', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31']))
            ->assertInertia(fn ($page) => $page
                ->where('period', 'monthly')
                ->has('rows', 2)
                ->where('rows.0.bucket', '2026-01')
                ->where('rows.0.net_sales', '100.00')
                ->where('rows.1.bucket', '2026-02')
                ->where('rows.1.net_sales', '200.00')
                ->where('totals.net_sales', '300.00'));
    }

    public function test_cost_and_profit_are_owner_only()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $cashier = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-03-10 12:00'));
        $transaction = $this->sell($cashier, $variant, 2); // gross 200, COGS 80

        // Return one unit the next day — its cost is credited back out of COGS.
        $this->travelTo(Carbon::parse('2026-03-11 12:00'));
        app(ProcessSaleReturn::class)->process(
            $transaction,
            'return',
            [['transaction_item_id' => $transaction->items()->first()->id, 'quantity' => 1]],
            null,
            'cashless',
            $cashier,
        );

        // Owner sees COGS + profit. Sale day: net 200, COGS 80, profit 120.
        // Return day: net −100, COGS −40 (returned cost credited), profit −60.
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('reports', ['period' => 'daily', 'date_from' => '2026-03-01', 'date_to' => '2026-03-31']))
            ->assertInertia(fn ($page) => $page
                ->where('canViewCost', true)
                ->where('rows.0.cogs', '80.00')
                ->where('rows.0.profit', '120.00')
                ->where('rows.1.cogs', '-40.00')
                ->where('rows.1.profit', '-60.00')
                ->where('totals.cogs', '40.00')
                ->where('totals.profit', '60.00'));

        // Admin never receives cost/profit — the keys are absent.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'daily', 'date_from' => '2026-03-01', 'date_to' => '2026-03-31']))
            ->assertInertia(fn ($page) => $page
                ->where('canViewCost', false)
                ->missing('rows.0.cogs')
                ->missing('rows.0.profit')
                ->missing('rows.0.uncosted_sales')
                ->missing('totals.profit')
                ->missing('totals.uncosted_sales'));
    }

    public function test_uncosted_items_are_split_out_of_profit()
    {
        $costed = $this->sellable(price: 100, cost: 40, stock: 50);
        $uncosted = $this->sellable(price: 100, cost: null, stock: 50);
        $cashier = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-03-10 12:00'));
        $this->sell($cashier, $costed, 1);   // gross 100, cost 40
        $this->sell($cashier, $uncosted, 1); // gross 100, no cost recorded

        // Net 200. Profit covers the costed item only: 100 − 40 = 60. The un-costed
        // item's 100 is reported as uncosted_sales, NOT as pure profit.
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('reports', ['period' => 'daily', 'date_from' => '2026-03-01', 'date_to' => '2026-03-31']))
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.net_sales', '200.00')
                ->where('rows.0.cogs', '40.00')
                ->where('rows.0.uncosted_sales', '100.00')
                ->where('rows.0.profit', '60.00')
                ->where('totals.uncosted_sales', '100.00')
                ->where('totals.profit', '60.00'));
    }

    public function test_transaction_discount_is_apportioned_across_the_uncosted_split()
    {
        $costed = $this->sellable(price: 100, cost: 40, stock: 50);
        $uncosted = $this->sellable(price: 100, cost: null, stock: 50);
        $cashier = User::factory()->cashier()->create();

        // One sale of both lines (subtotal 200) with a ₱20 transaction discount → total 180.
        $this->travelTo(Carbon::parse('2026-03-10 12:00'));
        $this->actingAs($cashier)
            ->post(route('pos.checkout'), [
                'lines' => [
                    ['variant_id' => $costed->id, 'quantity' => 1],
                    ['variant_id' => $uncosted->id, 'quantity' => 1],
                ],
                'payment_type' => 'cashless',
                'discount' => 20,
                'discount_type' => 'amount',
                'cash_received' => null,
            ])
            ->assertSessionHasNoErrors();

        // Net 180. The discount is apportioned by line share (50/50 here), so the
        // un-costed half is 90 and the costed half 90 → profit 90 − 40 = 50. The
        // split reconciles exactly to net (90 costed + 90 uncosted = 180).
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('reports', ['period' => 'daily', 'date_from' => '2026-03-01', 'date_to' => '2026-03-31']))
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.net_sales', '180.00')
                ->where('rows.0.uncosted_sales', '90.00')
                ->where('rows.0.cogs', '40.00')
                ->where('rows.0.profit', '50.00'));
    }
}
