<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Item;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryExportTest extends TestCase
{
    use RefreshDatabase;

    private function seedVariant(): Variant
    {
        $category = Category::factory()->create(['name' => 'Snacks']);
        $item = Item::factory()->create(['name' => 'Chips', 'category_id' => $category->id, 'unit' => 'pack']);
        $variant = Variant::factory()->for($item)->create([
            'name' => 'Regular',
            'sku' => 'CHIPS-1',
            'selling_price' => 20,
            'cost' => 12.5,
        ]);
        app(StockLedger::class)->record($variant, 42, 'stock_adjustment', null, 'manual_adjustment', null, User::factory()->owner()->create());

        return $variant;
    }

    public function test_owner_export_streams_active_variants_with_cost_column()
    {
        $this->seedVariant();

        $response = $this->actingAs(User::factory()->owner()->create())->get(route('inventory.export'));
        $response->assertOk();
        $content = $response->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim($content))));
        $this->assertStringContainsString('cost', $lines[0]);
        $this->assertStringContainsString('CHIPS-1', $content);
        $this->assertStringContainsString('Snacks', $content);
        $this->assertStringContainsString('42', $content);
        $this->assertStringContainsString('12.5', $content);
    }

    public function test_admin_export_omits_the_cost_column()
    {
        $this->seedVariant();

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('inventory.export'));
        $response->assertOk();
        $content = $response->streamedContent();

        $header = explode("\n", trim($content))[0];
        $this->assertStringNotContainsString('cost', $header);
        $this->assertStringNotContainsString('12.5', $content);
        $this->assertStringContainsString('CHIPS-1', $content);
    }

    public function test_archived_variants_are_excluded_from_export()
    {
        $this->seedVariant();
        Variant::factory()->archived()->create(['sku' => 'ARCHIVED-1']);

        $content = $this->actingAs(User::factory()->owner()->create())
            ->get(route('inventory.export'))
            ->streamedContent();

        $this->assertStringNotContainsString('ARCHIVED-1', $content);
    }

    public function test_template_download_includes_cost_for_owner_and_excludes_it_for_admin()
    {
        $ownerHeader = explode("\n", trim($this->actingAs(User::factory()->owner()->create())
            ->get(route('inventory.import.template'))->streamedContent()))[0];
        $this->assertStringContainsString('cost', $ownerHeader);
        $this->assertStringContainsString('mode', $ownerHeader);

        $adminHeader = explode("\n", trim($this->actingAs(User::factory()->admin()->create())
            ->get(route('inventory.import.template'))->streamedContent()))[0];
        $this->assertStringNotContainsString('cost', $adminHeader);
    }

    public function test_cashier_cannot_export()
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('inventory.export'))
            ->assertForbidden();
    }
}
