<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_view_stock_history()
    {
        $variant = Variant::factory()->create();
        app(StockLedger::class)->record($variant, 5, 'stock_adjustment', null, 'manual_adjustment', null, User::factory()->admin()->create());

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('stock-history.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('entries.data', 1));
    }

    public function test_history_can_be_scoped_to_a_single_variant()
    {
        $admin = User::factory()->admin()->create();
        $ledger = app(StockLedger::class);

        $target = Variant::factory()->create();
        $other = Variant::factory()->create();
        $ledger->record($target, 5, 'stock_adjustment', null, 'manual_adjustment', null, $admin);
        $ledger->record($other, 9, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        $this->actingAs($admin)
            ->get(route('stock-history.index', ['variant' => $target->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.variant.id', $target->id)
            );
    }

    public function test_history_can_be_filtered_by_type()
    {
        $admin = User::factory()->admin()->create();
        $ledger = app(StockLedger::class);
        $variant = Variant::factory()->create();

        $ledger->record($variant, 10, 'stock_adjustment', null, 'manual_adjustment', null, $admin);
        $ledger->record($variant, -2, 'sale', 'sale', 'transaction', null, $admin);

        $this->actingAs($admin)
            ->get(route('stock-history.index', ['type' => 'sale']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.type', 'sale')
            );
    }
}
