<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VariantUniquenessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<string, mixed>
     */
    private function payload(array $variants): array
    {
        return [
            'name' => 'Widget',
            'unit' => 'pc',
            'category_id' => null,
            'description' => null,
            'variants' => $variants,
        ];
    }

    private function variant(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Default',
            // SKU is mandatory, so the default is a unique value; tests that
            // exercise SKU behavior override it explicitly.
            'sku' => 'SKU-'.fake()->unique()->numerify('####'),
            'barcode' => null,
            'selling_price' => '10.00',
            'low_stock_threshold' => 0,
        ], $overrides);
    }

    public function test_duplicate_sku_across_items_is_rejected()
    {
        $other = Item::factory()->create();
        Variant::factory()->for($other)->create(['sku' => 'TAKEN']);

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->payload([$this->variant(['sku' => 'TAKEN'])]))
            ->assertSessionHasErrors('variants.0.sku');
    }

    public function test_duplicate_barcode_when_provided_is_rejected()
    {
        $other = Item::factory()->create();
        Variant::factory()->for($other)->create(['barcode' => '111222333']);

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->payload([$this->variant(['barcode' => '111222333'])]))
            ->assertSessionHasErrors('variants.0.barcode');
    }

    public function test_blank_barcodes_do_not_collide()
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->payload([
                $this->variant(['sku' => 'A-1', 'barcode' => '']),
                $this->variant(['sku' => 'A-2', 'barcode' => '']),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Variant::whereNull('barcode')->count());
    }

    public function test_duplicate_sku_within_the_same_payload_is_rejected()
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->payload([
                $this->variant(['sku' => 'DUP']),
                $this->variant(['sku' => 'DUP']),
            ]))
            ->assertSessionHasErrors('variants.1.sku');

        $this->assertSame(0, Item::count());
    }

    public function test_updating_a_variant_ignores_its_own_sku()
    {
        $item = Item::factory()->create(['name' => 'Widget']);
        $variant = Variant::factory()->for($item)->create(['sku' => 'KEEP-ME']);

        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('items.update', $item->id), $this->payload([
                [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => 'KEEP-ME',
                    'barcode' => null,
                    'selling_price' => '10.00',
                    'low_stock_threshold' => 0,
                ],
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_blank_sku_is_rejected()
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->payload([$this->variant(['sku' => ''])]))
            ->assertSessionHasErrors('variants.0.sku');

        $this->assertSame(0, Item::count());
    }

    public function test_sku_uniqueness_is_case_insensitive()
    {
        $other = Item::factory()->create();
        Variant::factory()->for($other)->create(['sku' => 'TAKEN']);

        // Input is uppercased before the unique check, so "taken" collides
        // with the stored "TAKEN".
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->payload([$this->variant(['sku' => 'taken'])]))
            ->assertSessionHasErrors('variants.0.sku');
    }

    public function test_sku_uniqueness_holds_against_archived_variants()
    {
        $other = Item::factory()->create();
        Variant::factory()->for($other)->archived()->create(['sku' => 'RETIRED']);

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('items.store'), $this->payload([$this->variant(['sku' => 'RETIRED'])]))
            ->assertSessionHasErrors('variants.0.sku');
    }
}
