<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\User;
use App\Support\Inventory\InventoryCsvSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Phase 18 S5 — CSV import edges beyond the Phase 7 suite: weird-but-valid
 * UTF-8 survives the import → export round-trip byte-identically.
 *
 * (Formula-injection names are deliberately not pinned here: finding F-16
 * changes how leading =/+/@ names are handled, and its test lands with that
 * S8 fix.)
 */
class InventoryImportEdgeTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<array<string, string>> $rows */
    private function csv(array $rows): UploadedFile
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, InventoryCsvSchema::COLUMNS);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (string $column) => $row[$column] ?? '',
                InventoryCsvSchema::COLUMNS,
            ));
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        $path = tempnam(sys_get_temp_dir(), 'inv').'.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, 'inventory.csv', 'text/csv', null, true);
    }

    public function test_multibyte_utf8_names_round_trip_through_import_and_export(): void
    {
        $owner = User::factory()->owner()->create();

        $name = 'Ñandú — 100% Kapé ☕ (テスト)';

        $this->actingAs($owner)
            ->post(route('inventory.import.store'), [
                'file' => $this->csv([[
                    'product' => $name,
                    'variant' => 'Größe M',
                    'sku' => 'UTF8-1',
                    'selling_price' => '12.34',
                    'stock' => '5',
                    'unit' => 'pcs',
                    'mode' => InventoryCsvSchema::MODE_REPLACE,
                ]]),
            ])
            ->assertSessionHasNoErrors();

        $item = Item::query()->firstWhere('name', $name);
        $this->assertNotNull($item, 'multibyte item name was not stored verbatim');
        $this->assertSame('Größe M', $item->variants()->sole()->name);

        // Export must carry the exact same bytes back out.
        $response = $this->actingAs($owner)->get(route('inventory.export'))->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString($name, $csv);
        $this->assertStringContainsString('Größe M', $csv);
    }
}
