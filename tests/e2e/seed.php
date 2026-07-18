<?php

/**
 * E2E database seed — executed by tests/e2e/serve.sh (via artisan tinker)
 * against the ISOLATED runtime copy, never the real database.
 *
 * Logins (password "password"): the existing Owner (password reset for
 * determinism), e2e-admin (Admin), e2e-cashier (Cashier).
 * Fixture product: "E2E Smoke Coffee", unnamed variant, SKU E2ESMOKE,
 * ₱25.00 / cost ₱10.00, stock 50 — seeded through the ledger.
 */

use App\Models\Item;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;

$admin = User::factory()->admin()->create(['username' => 'e2e-admin', 'name' => 'E2E Admin']);
User::factory()->cashier()->create(['username' => 'e2e-cashier', 'name' => 'E2E Cashier']);

$owner = User::query()->where('role', 'owner')->firstOrFail();
$owner->password = 'password';
$owner->name = 'E2E Owner';
$owner->save();

$item = Item::factory()->create(['name' => 'E2E Smoke Coffee']);
$variant = Variant::factory()->create([
    'item_id' => $item->id,
    'name' => null,
    'sku' => 'E2ESMOKE',
    'barcode' => null,
    'selling_price' => '25.00',
    'cost' => '10.00',
]);

app(StockLedger::class)->record($variant, 50, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

echo "e2e seed complete\n";
