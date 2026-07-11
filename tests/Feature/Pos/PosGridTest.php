<?php

namespace Tests\Feature\Pos;

use App\Models\Item;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosGridTest extends TestCase
{
    use RefreshDatabase;

    private function stock(Variant $variant, int $quantity): void
    {
        app(StockLedger::class)->record(
            $variant, $quantity, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );
    }

    public function test_cashier_can_open_the_pos_screen()
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('pos'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('products'));
    }

    public function test_grid_lists_active_products_and_excludes_archived_ones()
    {
        $active = Variant::factory()->create(['name' => 'Small']);
        $this->stock($active, 4);

        Variant::factory()->archived()->create();                       // archived variant
        Variant::factory()->create(['item_id' => Item::factory()->archived()]); // archived item

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('pos'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.total', 1)
                ->where('products.data.0.id', $active->id)
                ->where('products.data.0.current_stock', 4));
    }

    public function test_search_treats_like_wildcards_literally()
    {
        // A "%" in the query must match a literal percent, not act as a wildcard.
        $matching = Variant::factory()->create(['item_id' => Item::factory()->create(['name' => '50% off soap'])]);
        $this->stock($matching, 2);
        Variant::factory()->create(['item_id' => Item::factory()->create(['name' => 'Plain water'])]);

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('pos', ['search' => '50%']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.total', 1)
                ->where('products.data.0.id', $matching->id));
    }

    public function test_out_of_stock_products_stay_visible_with_zero_stock()
    {
        Variant::factory()->create(); // never stocked → 0 on hand

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('pos'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.total', 1)
                ->where('products.data.0.current_stock', 0));
    }

    public function test_lookup_returns_an_exact_barcode_match_without_cost()
    {
        $variant = Variant::factory()->create(['barcode' => '4801234567890']);
        $this->stock($variant, 3);

        $response = $this->actingAs(User::factory()->cashier()->create())
            ->getJson(route('pos.lookup', ['code' => '4801234567890']));

        $response->assertOk()
            ->assertJsonPath('variant.id', $variant->id)
            ->assertJsonMissingPath('variant.cost');
    }

    public function test_lookup_matches_sku_case_insensitively()
    {
        $variant = Variant::factory()->create(['sku' => 'ABC-123']);
        $this->stock($variant, 3);

        $this->actingAs(User::factory()->cashier()->create())
            ->getJson(route('pos.lookup', ['code' => 'abc-123']))
            ->assertOk()
            ->assertJsonPath('variant.id', $variant->id);
    }

    public function test_lookup_returns_null_on_a_miss()
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->getJson(route('pos.lookup', ['code' => 'NOPE-000']))
            ->assertOk()
            ->assertJsonPath('variant', null);
    }
}
