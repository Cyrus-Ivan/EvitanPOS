<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\ImportHistory;
use App\Models\Item;
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
        $item = Item::factory()->create(['name' => 'Bottled Water']);
        $variant = Variant::factory()->for($item)->create([
            'sku' => 'SKU-1',
            'selling_price' => 5,
            'low_stock_threshold' => 2,
        ]);
        app(StockLedger::class)->record($variant, 5, 'stock_adjustment', null, 'manual_adjustment', null, $owner);

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['category' => '', 'sku' => 'SKU-1', 'selling_price' => '25.00', 'low_stock_threshold' => '8', 'stock' => '20']),
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
        $item = Item::factory()->create(['name' => 'Bottled Water']);
        $variant = Variant::factory()->for($item)->create(['sku' => 'SKU-1']);
        app(StockLedger::class)->record($variant, 10, 'stock_adjustment', null, 'manual_adjustment', null, $owner);

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['category' => '', 'sku' => 'SKU-1', 'stock' => '5', 'mode' => 'add_to_current_stock']),
            ])])
            ->assertSessionHasNoErrors();

        $this->assertSame(15, $this->stockOf($variant->refresh()));
        $this->assertDatabaseHas('inventory_ledger', ['variant_id' => $variant->id, 'quantity' => 5]);
    }

    public function test_negative_add_beyond_stock_fails_the_whole_import()
    {
        $owner = User::factory()->owner()->create();
        $item = Item::factory()->create(['name' => 'Bottled Water']);
        $existing = Variant::factory()->for($item)->create(['sku' => 'SKU-1']);
        app(StockLedger::class)->record($existing, 3, 'stock_adjustment', null, 'manual_adjustment', null, $owner);

        // Row 1 is a valid new product; row 2 drives SKU-1 below zero.
        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['sku' => 'NEW-1', 'product' => 'New Product', 'variant' => 'A', 'stock' => '10', 'mode' => 'add_to_current_stock']),
                $this->row(['category' => '', 'sku' => 'SKU-1', 'stock' => '-10', 'mode' => 'add_to_current_stock']),
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

    public function test_import_rejects_adding_a_named_variant_to_an_existing_no_variant_item()
    {
        $owner = User::factory()->owner()->create();
        $item = Item::factory()->create(['name' => 'Bottled Water']);
        Variant::factory()->for($item)->create(['name' => null, 'sku' => 'WATER-1']);

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['category' => '', 'product' => 'Bottled Water', 'variant' => '500ml', 'sku' => 'WATER-500']),
            ])]);

        // The named variant would leave the item mixing an unnamed + named row.
        $this->assertNull(Variant::where('sku', 'WATER-500')->first());
        $import = ImportHistory::first();
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsStringIgnoringCase('already exists as a single', json_encode($import->errors));
    }

    public function test_import_can_convert_a_no_variant_item_by_naming_the_existing_and_adding()
    {
        $owner = User::factory()->owner()->create();
        $item = Item::factory()->create(['name' => 'Bottled Water']);
        $existing = Variant::factory()->for($item)->create(['name' => null, 'sku' => 'WATER-1']);

        // Naming the existing variant (by its SKU) and adding a second one in the
        // same file is the supported CSV conversion path.
        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['category' => '', 'product' => 'Bottled Water', 'variant' => '330ml', 'sku' => 'WATER-1', 'stock' => '0', 'mode' => 'add_to_current_stock']),
                $this->row(['category' => '', 'product' => 'Bottled Water', 'variant' => '500ml', 'sku' => 'WATER-500', 'stock' => '0', 'mode' => 'add_to_current_stock']),
            ])])
            ->assertSessionHasNoErrors();

        $this->assertSame('success', ImportHistory::first()->status);
        $this->assertSame('330ml', $existing->fresh()->name);
        $this->assertSame('500ml', Variant::where('sku', 'WATER-500')->first()->name);
        $this->assertSame(2, $item->variants()->active()->count());
    }

    public function test_import_rejects_a_file_mixing_blank_and_named_variants_for_one_product()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['product' => 'Soda', 'variant' => 'Cola', 'sku' => 'SODA-1']),
                $this->row(['product' => 'Soda', 'variant' => '', 'sku' => 'SODA-2']),
            ])]);

        $this->assertSame(0, Variant::count());
        $import = ImportHistory::first();
        $this->assertSame('failed', $import->status);
        // The blank row is flagged as sitting alongside named variant rows.
        $this->assertStringContainsStringIgnoringCase('no variant name', json_encode($import->errors));
        $this->assertStringContainsStringIgnoringCase('named variant rows', json_encode($import->errors));
    }

    public function test_import_rejects_multiple_blank_variants_for_one_product()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['product' => 'Soda', 'variant' => '', 'sku' => 'SODA-1']),
                $this->row(['product' => 'Soda', 'variant' => '', 'sku' => 'SODA-2']),
            ])]);

        $this->assertSame(0, Variant::count());
        $import = ImportHistory::first();
        $this->assertSame('failed', $import->status);
        // Two blank rows for a brand-new product read as duplicates, not a mix.
        $this->assertStringContainsStringIgnoringCase('same item', json_encode($import->errors));
        $this->assertStringContainsStringIgnoringCase('duplicate row', json_encode($import->errors));
    }

    public function test_import_rejects_a_row_matching_an_archived_variant()
    {
        $owner = User::factory()->owner()->create();
        Variant::factory()->archived()->create(['sku' => 'ARCH-1']);

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['sku' => 'ARCH-1']),
            ])]);

        $import = ImportHistory::first();
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsStringIgnoringCase('archived variant', json_encode($import->errors));
        $this->assertDatabaseMissing('inventory_ledger', ['reference_type' => 'csv_import']);
    }

    public function test_import_rejects_a_new_row_matching_an_archived_item_name()
    {
        $owner = User::factory()->owner()->create();
        Item::factory()->archived()->create(['name' => 'Old Product']);

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['product' => 'Old Product', 'variant' => 'A', 'sku' => 'NEW-X']),
            ])]);

        $this->assertNull(Variant::where('sku', 'NEW-X')->first());
        $import = ImportHistory::first();
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsStringIgnoringCase('archived item', json_encode($import->errors));
    }

    public function test_import_uppercases_new_skus()
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['sku' => 'water-500']),
            ])])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Variant::where('sku', 'WATER-500')->first());
        $this->assertNull(Variant::where('sku', 'water-500')->first());
    }

    public function test_import_matches_existing_variants_by_sku_case_insensitively()
    {
        $owner = User::factory()->owner()->create();
        $item = Item::factory()->create(['name' => 'Bottled Water']);
        $variant = Variant::factory()->for($item)->create(['sku' => 'WATER-500']);

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['category' => '', 'sku' => 'water-500', 'selling_price' => '42.00']),
            ])])
            ->assertSessionHasNoErrors();

        // The lowercase SKU matched the stored uppercase one: no duplicate.
        $this->assertSame(1, Variant::count());
        $this->assertSame('42.00', $variant->fresh()->selling_price);
    }

    public function test_import_strips_a_utf8_bom_from_the_header()
    {
        $owner = User::factory()->owner()->create();
        $file = $this->csv([$this->row()]);
        // Excel's "CSV UTF-8" prepends a BOM, which lands on the first header
        // cell (category).
        file_put_contents($file->getRealPath(), "\xEF\xBB\xBF".file_get_contents($file->getRealPath()));

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $file])
            ->assertSessionHasNoErrors();

        $this->assertSame('success', ImportHistory::first()->status);
        // The category column (first, behind the BOM) was recognized, not
        // silently dropped.
        $this->assertDatabaseHas('categories', ['name' => 'Beverages']);
    }

    public function test_non_utf8_file_fails_with_a_friendly_error()
    {
        $owner = User::factory()->owner()->create();
        $file = $this->csv([$this->row()]);
        // "café" in latin-1: \xE9 is not valid UTF-8.
        file_put_contents($file->getRealPath(), file_get_contents($file->getRealPath())."caf\xE9");

        $this->actingAs($owner)->post(route('inventory.import.store'), ['file' => $file]);

        $this->assertSame(0, Variant::count());
        $import = ImportHistory::first();
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsString('UTF-8', json_encode($import->errors));
    }

    public function test_an_unexpected_exception_records_a_failed_import()
    {
        $owner = User::factory()->owner()->create();

        $this->mock(StockLedger::class, function ($mock) {
            $mock->shouldReceive('currentStock')->andReturn(0);
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('boom'));
        });

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([$this->row()])])
            ->assertRedirect();

        // No 500, no partial writes — just a failed history entry.
        $this->assertSame(0, Variant::count());
        $this->assertSame(0, Item::count());
        $import = ImportHistory::first();
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsStringIgnoringCase('something went wrong', json_encode($import->errors));
    }

    public function test_blank_cells_preserve_existing_values_on_matched_variants()
    {
        $owner = User::factory()->owner()->create();
        $item = Item::factory()->create([
            'name' => 'Bottled Water',
            'unit' => 'box',
            'description' => 'Original description',
        ]);
        $variant = Variant::factory()->for($item)->create([
            'sku' => 'SKU-1',
            'name' => '500ml',
            'barcode' => '9998887776665',
            'low_stock_threshold' => 7,
        ]);

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row([
                    'category' => '',
                    'sku' => 'SKU-1',
                    'variant' => '',
                    'barcode' => '',
                    'unit' => '',
                    'description' => '',
                    'low_stock_threshold' => '',
                    'cost' => '',
                    'selling_price' => '20.00',
                    'stock' => '0',
                    'mode' => 'add_to_current_stock',
                ]),
            ])])
            ->assertSessionHasNoErrors();

        $variant->refresh();
        $this->assertSame('20.00', $variant->selling_price); // required cell applied
        $this->assertSame('500ml', $variant->name); // blank keeps the name
        $this->assertSame('9998887776665', $variant->barcode); // not wiped
        $this->assertSame(7, $variant->low_stock_threshold); // not reset
        $this->assertSame('box', $item->fresh()->unit); // not defaulted
        $this->assertSame('Original description', $item->fresh()->description); // not wiped
    }

    public function test_blank_variant_cell_keeps_the_existing_name_and_passes_the_shape_check()
    {
        $owner = User::factory()->owner()->create();
        $item = Item::factory()->create(['name' => 'Bottled Water']);
        $existing = Variant::factory()->for($item)->create(['sku' => 'WATER-1', 'name' => '330ml']);

        // A blank variant cell on an existing SKU keeps "330ml" — it is not an
        // unnamed row, so adding a named sibling is legal.
        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['category' => '', 'sku' => 'WATER-1', 'variant' => '', 'stock' => '0', 'mode' => 'add_to_current_stock']),
                $this->row(['category' => '', 'sku' => 'WATER-500', 'variant' => '500ml', 'stock' => '0', 'mode' => 'add_to_current_stock']),
            ])])
            ->assertSessionHasNoErrors();

        $this->assertSame('success', ImportHistory::first()->status);
        $this->assertSame('330ml', $existing->fresh()->name);
        $this->assertSame(2, $item->variants()->active()->count());
    }

    public function test_new_variant_row_updates_the_existing_items_unit_and_description()
    {
        $owner = User::factory()->owner()->create();
        $item = Item::factory()->create([
            'name' => 'Bottled Water',
            'unit' => 'pc',
            'description' => 'Original description',
        ]);
        Variant::factory()->for($item)->create(['sku' => 'WATER-500', 'name' => '500ml']);

        // The row attaches a NEW variant to an existing item; its non-blank
        // unit/description cells must update the item exactly as a
        // matched-by-SKU row would.
        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row([
                    'category' => '',
                    'sku' => 'WATER-1L',
                    'variant' => '1L',
                    'unit' => 'box',
                    'description' => 'Bulk drinking water',
                ]),
            ])])
            ->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('box', $item->unit);
        $this->assertSame('Bulk drinking water', $item->description);
    }

    public function test_later_row_fills_unit_left_blank_by_the_first_row_of_a_new_product()
    {
        $owner = User::factory()->owner()->create();

        // Row 1 creates the product with a blank unit (defaults to "pc");
        // row 2's explicit unit is not a conflict (blank = no opinion) and
        // must land on the item instead of being silently dropped.
        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['sku' => 'WATER-500', 'variant' => '500ml', 'unit' => '', 'description' => '']),
                $this->row(['sku' => 'WATER-1L', 'variant' => '1L', 'unit' => 'box', 'description' => 'Bulk drinking water']),
            ])])
            ->assertSessionHasNoErrors();

        $item = Item::whereRaw('lower(name) = ?', ['bottled water'])->first();
        $this->assertNotNull($item);
        $this->assertSame('box', $item->unit);
        $this->assertSame('Bulk drinking water', $item->description);
    }

    public function test_import_rejects_a_product_name_mismatch_for_an_existing_sku()
    {
        $owner = User::factory()->owner()->create();
        $item = Item::factory()->create(['name' => 'Bottled Water']);
        Variant::factory()->for($item)->create(['sku' => 'SKU-1']);

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['category' => '', 'product' => 'Cola', 'sku' => 'SKU-1']),
            ])]);

        $import = ImportHistory::first();
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsStringIgnoringCase('rename', json_encode($import->errors));
        $this->assertSame('Bottled Water', $item->fresh()->name);
    }

    public function test_import_rejects_a_category_mismatch_for_an_existing_item()
    {
        $owner = User::factory()->owner()->create();
        $item = Item::factory()->create(['name' => 'Bottled Water', 'category_id' => null]);
        Variant::factory()->for($item)->create(['sku' => 'SKU-1']);

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['category' => 'New Cat', 'sku' => 'SKU-1']),
            ])]);

        $import = ImportHistory::first();
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsStringIgnoringCase('move products between categories', json_encode($import->errors));
        // The rejected row must not leave an orphan category behind.
        $this->assertDatabaseMissing('categories', ['name' => 'New Cat']);
        $this->assertNull($item->fresh()->category_id);
    }

    public function test_import_rejects_a_row_naming_an_archived_category()
    {
        $owner = User::factory()->owner()->create();
        Category::factory()->create(['name' => 'Beverages', 'archived_at' => now()]);

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([$this->row()])]);

        $this->assertSame(0, Variant::count());
        $import = ImportHistory::first();
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsStringIgnoringCase('archived', json_encode($import->errors));
        $this->assertStringContainsStringIgnoringCase('restore', json_encode($import->errors));
    }

    public function test_import_rejects_rows_that_disagree_on_new_product_fields()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['product' => 'Soda', 'variant' => 'A', 'sku' => 'S-1', 'unit' => 'pc']),
                $this->row(['product' => 'Soda', 'variant' => 'B', 'sku' => 'S-2', 'unit' => 'box']),
            ])]);

        $this->assertSame(0, Variant::count());
        $import = ImportHistory::first();
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsStringIgnoringCase('disagree on unit', json_encode($import->errors));
    }

    public function test_import_records_mixed_mode()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['variant' => 'A', 'sku' => 'S-1', 'mode' => 'replace_stock_count']),
                $this->row(['variant' => 'B', 'sku' => 'S-2', 'mode' => 'add_to_current_stock']),
            ])])
            ->assertSessionHasNoErrors();

        $import = ImportHistory::first();
        $this->assertSame('success', $import->status);
        $this->assertSame('mixed', $import->mode);
    }

    public function test_import_rejects_overlong_fields()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['product' => str_repeat('A', 256), 'unit' => str_repeat('u', 51)]),
            ])]);

        $this->assertSame(0, Variant::count());
        $import = ImportHistory::first();
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsString('Product must be 255 characters or fewer.', json_encode($import->errors));
        $this->assertStringContainsString('Unit must be 50 characters or fewer.', json_encode($import->errors));
    }

    public function test_import_rejects_out_of_range_numbers()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                // Would previously saturate the (int) cast / overflow the money cap.
                $this->row(['stock' => '99999999999999999999', 'selling_price' => '99999999999.00']),
            ])]);

        $this->assertSame(0, Variant::count());
        $import = ImportHistory::first();
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsStringIgnoringCase('stock must be a whole number between', json_encode($import->errors));
        $this->assertStringContainsStringIgnoringCase('selling price must be a number between', json_encode($import->errors));
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

    public function test_ascii_case_variant_product_name_matches_even_with_unicode_present()
    {
        $owner = User::factory()->owner()->create();
        $item = Item::factory()->create(['name' => 'CAFÉ LATTE']);
        Variant::factory()->for($item)->create(['sku' => 'CAFE-1', 'name' => 'Small']);

        // Only ASCII letters differ in case (the É is untouched) — this must
        // resolve to the existing item, not trip the immutable-name check or
        // create a duplicate.
        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['product' => 'cafÉ latte', 'category' => '', 'sku' => 'CAFE-1', 'variant' => 'Small']),
            ])])
            ->assertSessionHasNoErrors();

        $this->assertSame('success', ImportHistory::first()->status);
        $this->assertSame(1, Item::count());
        $this->assertSame('CAFÉ LATTE', $item->fresh()->name); // never renamed
    }

    public function test_non_ascii_case_variant_product_name_is_a_distinct_item_matching_the_nocase_index()
    {
        $owner = User::factory()->owner()->create();
        $item = Item::factory()->create(['name' => 'CAFÉ LATTE']);
        Variant::factory()->for($item)->create(['sku' => 'CAFE-1', 'name' => 'Small']);

        // 'é' vs 'É' is beyond SQLite's ASCII-only NOCASE, so the lookup and
        // the items_name_unique index must agree: this is a different name and
        // becomes its own item — never a silent write onto the existing one.
        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['product' => 'Café Latte', 'category' => '', 'sku' => 'CAFE-2', 'variant' => 'Small']),
            ])])
            ->assertSessionHasNoErrors();

        $this->assertSame('success', ImportHistory::first()->status);
        $this->assertSame(2, Item::count());
        $this->assertSame($item->id, Variant::where('sku', 'CAFE-1')->first()->item_id);
        $this->assertNotSame($item->id, Variant::where('sku', 'CAFE-2')->first()->item_id);
    }

    public function test_barcode_uniqueness_is_case_sensitive_like_the_unique_index()
    {
        $owner = User::factory()->owner()->create();
        Variant::factory()->create(['sku' => 'EXIST-1', 'barcode' => 'ABC-111']);

        // The DB index and the item form both treat barcodes case-sensitively;
        // the import must not be stricter than what it round-trips with.
        $this->actingAs($owner)
            ->post(route('inventory.import.store'), ['file' => $this->csv([
                $this->row(['sku' => 'NEW-1', 'variant' => 'Small', 'barcode' => 'abc-111']),
                $this->row(['sku' => 'NEW-2', 'variant' => 'Large', 'barcode' => 'AbC-111']),
            ])])
            ->assertSessionHasNoErrors();

        $this->assertSame('success', ImportHistory::first()->status);
        $this->assertSame('abc-111', Variant::where('sku', 'NEW-1')->first()->barcode);
        $this->assertSame('AbC-111', Variant::where('sku', 'NEW-2')->first()->barcode);
    }
}
