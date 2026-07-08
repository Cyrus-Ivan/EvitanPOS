<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Item;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_phase_one_tables_exist()
    {
        $tables = [
            'users', 'categories', 'items', 'variants', 'inventory_ledger',
            'transactions', 'transaction_items', 'audit_logs', 'login_history',
            'import_history', 'settings',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_models_generate_uuid_primary_keys()
    {
        $category = Category::create(['name' => 'Beverages']);

        $this->assertTrue(Str::isUuid($category->id));
    }

    public function test_variant_requires_a_valid_item_foreign_key()
    {
        $this->expectException(QueryException::class);

        Variant::create([
            'item_id' => (string) Str::uuid(), // non-existent item
            'name' => 'Orphan',
            'selling_price' => 10.00,
        ]);
    }

    public function test_username_must_be_unique()
    {
        User::factory()->create(['username' => 'duplicate']);

        $this->expectException(QueryException::class);

        User::factory()->create(['username' => 'duplicate']);
    }

    public function test_sku_and_barcode_are_unique_when_provided_but_allow_multiple_nulls()
    {
        $item = $this->makeItem();

        // Multiple NULL sku/barcode are allowed.
        Variant::create(['item_id' => $item->id, 'name' => 'A', 'selling_price' => 1]);
        Variant::create(['item_id' => $item->id, 'name' => 'B', 'selling_price' => 1]);

        Variant::create(['item_id' => $item->id, 'name' => 'C', 'selling_price' => 1, 'sku' => 'SKU-1']);

        $this->expectException(QueryException::class);

        Variant::create(['item_id' => $item->id, 'name' => 'D', 'selling_price' => 1, 'sku' => 'SKU-1']);
    }

    public function test_users_use_soft_deletes()
    {
        $user = User::factory()->create();
        $id = $user->id;

        $user->delete();

        $this->assertNull(User::find($id));
        $this->assertNotNull(User::withTrashed()->find($id));
    }

    public function test_archived_scope_hides_archived_rows_but_keeps_them_queryable()
    {
        $active = Category::create(['name' => 'Active']);
        $archived = Category::create(['name' => 'Archived', 'archived_at' => now()]);

        $this->assertEqualsCanonicalizing(
            [$active->id, $archived->id],
            Category::pluck('id')->all(),
            'Archived rows must remain in the default (unscoped) query.'
        );

        $this->assertSame([$active->id], Category::active()->pluck('id')->all());
    }

    public function test_inventory_ledger_has_no_updated_at()
    {
        $item = $this->makeItem();
        $variant = Variant::create(['item_id' => $item->id, 'name' => 'V', 'selling_price' => 5]);
        $user = User::factory()->create();

        $entry = InventoryLedger::create([
            'variant_id' => $variant->id,
            'quantity' => 10,
            'resulting_snapshot' => 10,
            'type' => 'stock_adjustment',
            'reference_type' => 'manual_adjustment',
            'performed_by' => $user->id,
        ]);

        $this->assertFalse(Schema::hasColumn('inventory_ledger', 'updated_at'));
        $this->assertNotNull($entry->created_at);
    }

    private function makeItem(): Item
    {
        return Item::create(['name' => 'Item', 'unit' => 'pc']);
    }
}
