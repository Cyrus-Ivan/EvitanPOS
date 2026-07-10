<?php

namespace Tests\Feature\Inventory;

use App\Models\ImportHistory;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\InventoryCsvSchema;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class InventoryImportTest extends TestCase
{
    use RefreshDatabase;

    private function stockOf(Variant $variant): int
    {
        return app(StockLedger::class)->currentStock($variant);
    }

    /**
     * A well-formed data row, keyed by canonical column. Override any cell.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'category' => 'Beverages',
            'product' => 'Bottled Water',
            'variant' => '500ml',
            'sku' => 'WATER-500',
            'barcode' => '',
            'selling_price' => '15.00',
            'cost' => '9.50',
            'stock' => '100',
            'unit' => 'pc',
            'description' => 'Purified water',
            'low_stock_threshold' => '12',
            'mode' => 'replace_stock_count',
        ], $overrides);
    }

    /**
     * Build an uploaded CSV from rows. Pass $columns to omit/reorder headers.
     *
     * @param  list<array<string, string>>  $rows
     * @param  list<string>|null  $columns
     */
    private function csv(array $rows, ?array $columns = null): UploadedFile
    {
        $columns ??= InventoryCsvSchema::COLUMNS;

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $column) => $row[$column] ?? '', $columns));
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        $path = tempnam(sys_get_temp_dir(), 'inv').'.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, 'inventory.csv', 'text/csv', null, true);
    }

    public function test_owner_import_creates_category_item_variant_and_stock()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([$this->row()])])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $variant = Variant::where('sku', 'WATER-500')->first();
        $this->assertNotNull($variant);
        $this->assertSame('500ml', $variant->name);
        $this->assertSame('15.00', $variant->selling_price);
        $this->assertSame('9.50', $variant->cost);
        $this->assertSame(12, $variant->low_stock_threshold);
        $this->assertSame(100, $this->stockOf($variant));

        $this->assertDatabaseHas('categories', ['name' => 'Beverages']);
        $this->assertDatabaseHas('items', ['name' => 'Bottled Water', 'unit' => 'pc']);
        $this->assertSame('Beverages', $variant->item->category->name);

        $this->assertDatabaseHas('inventory_ledger', [
            'variant_id' => $variant->id,
            'quantity' => 100,
            'type' => 'stock_adjustment',
            'reference_type' => 'csv_import',
        ]);

        $import = ImportHistory::first();
        $this->assertSame('success', $import->status);
        $this->assertSame(1, $import->total_rows);
        $this->assertSame(1, $import->success_count);
        $this->assertSame(0, $import->error_count);
    }

    public function test_import_allows_blank_variant_name()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['sku' => 'SOLO-1', 'variant' => '']),
            ])])
            ->assertSessionHasNoErrors();

        $variant = Variant::where('sku', 'SOLO-1')->first();
        $this->assertNotNull($variant);
        $this->assertNull($variant->name);
    }

    public function test_import_succeeds_without_a_variant_column()
    {
        $owner = User::factory()->owner()->create();
        // The variant column is optional; a file may omit it entirely.
        $columns = array_values(array_diff(InventoryCsvSchema::COLUMNS, ['variant']));

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([$this->row(['sku' => 'SOLO-2'])], $columns)])
            ->assertSessionHasNoErrors();

        $variant = Variant::where('sku', 'SOLO-2')->first();
        $this->assertNotNull($variant);
        $this->assertNull($variant->name);
    }

    public function test_import_updates_existing_variant_fields_and_replaces_stock()
    {
        $owner = User::factory()->owner()->create();
        $variant = Variant::factory()->create([
            'sku' => 'SKU-1',
            'selling_price' => 5,
            'low_stock_threshold' => 2,
        ]);
        app(StockLedger::class)->record($variant, 5, 'stock_adjustment', null, 'manual_adjustment', null, $owner);

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['sku' => 'SKU-1', 'selling_price' => '25.00', 'low_stock_threshold' => '8', 'stock' => '20']),
            ])])
            ->assertSessionHasNoErrors();

        $variant->refresh();
        $this->assertSame('25.00', $variant->selling_price);
        $this->assertSame(8, $variant->low_stock_threshold);
        // Replace-count from 5 -> 20 is a +15 delta.
        $this->assertSame(20, $this->stockOf($variant));
        $this->assertDatabaseHas('inventory_ledger', [
            'variant_id' => $variant->id,
            'quantity' => 15,
            'reference_type' => 'csv_import',
        ]);
        // No duplicate variant created; the SKU matched the existing one.
        $this->assertSame(1, Variant::count());
    }

    public function test_add_mode_adds_to_current_stock()
    {
        $owner = User::factory()->owner()->create();
        $variant = Variant::factory()->create(['sku' => 'SKU-1']);
        app(StockLedger::class)->record($variant, 10, 'stock_adjustment', null, 'manual_adjustment', null, $owner);

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['sku' => 'SKU-1', 'stock' => '5', 'mode' => 'add_to_current_stock']),
            ])])
            ->assertSessionHasNoErrors();

        $this->assertSame(15, $this->stockOf($variant->refresh()));
        $this->assertDatabaseHas('inventory_ledger', ['variant_id' => $variant->id, 'quantity' => 5]);
    }

    public function test_negative_add_beyond_stock_fails_the_whole_import()
    {
        $owner = User::factory()->owner()->create();
        $existing = Variant::factory()->create(['sku' => 'SKU-1']);
        app(StockLedger::class)->record($existing, 3, 'stock_adjustment', null, 'manual_adjustment', null, $owner);

        // Row 1 is a valid new product; row 2 drives SKU-1 below zero.
        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['sku' => 'NEW-1', 'product' => 'New Product', 'variant' => 'A', 'stock' => '10', 'mode' => 'add_to_current_stock']),
                $this->row(['sku' => 'SKU-1', 'stock' => '-10', 'mode' => 'add_to_current_stock']),
            ])]);

        // All-or-nothing: nothing from the file was applied.
        $this->assertNull(Variant::where('sku', 'NEW-1')->first());
        $this->assertSame(3, $this->stockOf($existing->refresh()));
        $this->assertSame(1, $existing->ledgerEntries()->count()); // only the seed
        $this->assertDatabaseMissing('inventory_ledger', ['reference_type' => 'csv_import']);

        $import = ImportHistory::first();
        $this->assertSame('failed', $import->status);
        $this->assertSame(0, $import->success_count);
    }

    public function test_in_file_duplicate_sku_fails_and_applies_nothing()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['sku' => 'DUP', 'variant' => 'A']),
                $this->row(['sku' => 'DUP', 'variant' => 'B']),
            ])]);

        $this->assertSame(0, Variant::count());
        $import = ImportHistory::first();
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsStringIgnoringCase('appears on more than one row', json_encode($import->errors));
    }

    public function test_in_file_duplicate_barcode_fails()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['sku' => 'A', 'barcode' => '1234567890123']),
                $this->row(['sku' => 'B', 'barcode' => '1234567890123']),
            ])]);

        $this->assertSame(0, Variant::count());
        $this->assertSame('failed', ImportHistory::first()->status);
    }

    public function test_barcode_collision_with_existing_variant_fails()
    {
        $owner = User::factory()->owner()->create();
        Variant::factory()->create(['sku' => 'EXISTING', 'barcode' => '9990001112223']);

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['sku' => 'NEW', 'barcode' => '9990001112223']),
            ])]);

        $this->assertNull(Variant::where('sku', 'NEW')->first());
        $this->assertSame('failed', ImportHistory::first()->status);
    }

    public function test_missing_required_column_fails()
    {
        $owner = User::factory()->owner()->create();
        // Drop the sku column entirely.
        $columns = array_values(array_diff(InventoryCsvSchema::COLUMNS, ['sku']));

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([$this->row()], $columns)]);

        $this->assertSame(0, Variant::count());
        $import = ImportHistory::first();
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsStringIgnoringCase('missing required column', json_encode($import->errors));
    }

    public function test_missing_required_field_fails()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([$this->row(['product' => ''])])]);

        $this->assertSame(0, Variant::count());
        $this->assertSame('failed', ImportHistory::first()->status);
    }

    public function test_invalid_price_fails()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([$this->row(['selling_price' => 'abc'])])]);

        $this->assertSame(0, Variant::count());
        $this->assertSame('failed', ImportHistory::first()->status);
    }

    public function test_invalid_stock_number_fails()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([$this->row(['stock' => '12.5'])])]);

        $this->assertSame(0, Variant::count());
        $this->assertSame('failed', ImportHistory::first()->status);
    }

    public function test_cost_is_ignored_for_admin_but_applied_for_owner()
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->post(route('inventory.import.store'), ['file' => $this->csv([$this->row(['sku' => 'ADMIN-1', 'cost' => '9.50'])])])
            ->assertSessionHasNoErrors();
        $this->assertNull(Variant::where('sku', 'ADMIN-1')->first()->cost);

        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([$this->row(['sku' => 'OWNER-1', 'cost' => '9.50'])])])
            ->assertSessionHasNoErrors();
        $this->assertSame('9.50', Variant::where('sku', 'OWNER-1')->first()->cost);
    }

    public function test_ledger_rows_reference_the_import_history_record()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([$this->row(['stock' => '30'])])])
            ->assertSessionHasNoErrors();

        $import = ImportHistory::first();
        $variant = Variant::where('sku', 'WATER-500')->first();
        $this->assertDatabaseHas('inventory_ledger', [
            'variant_id' => $variant->id,
            'reference_type' => 'csv_import',
            'reference_id' => $import->id,
        ]);
    }

    public function test_cashier_cannot_access_import_routes()
    {
        $cashier = User::factory()->cashier()->create();

        $this->actingAs($cashier)->get(route('inventory.import'))->assertForbidden();
        $this->actingAs($cashier)->get(route('inventory.import.template'))->assertForbidden();
        $this->actingAs($cashier)
            ->post(route('inventory.import.store'), ['file' => $this->csv([$this->row()])])
            ->assertForbidden();

        $this->assertSame(0, Variant::count());
    }
}
