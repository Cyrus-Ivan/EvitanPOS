<?php

namespace App\Support\Inventory;

use App\Exceptions\ImportRowException;
use App\Exceptions\NegativeInventoryException;
use App\Models\Category;
use App\Models\ImportHistory;
use App\Models\Item;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk inventory CSV import (Phase 7). Parses an uploaded CSV, fully validates
 * every row, and — only when the whole file is clean — applies it inside a
 * single transaction: upserting Categories/Items/Variants and funnelling every
 * stock change through StockLedger::record() so the never-negative and
 * append-only invariants hold.
 *
 * All-or-nothing by design: a single bad row (validation or a write that would
 * drive stock below zero) aborts the entire import and nothing is persisted to
 * the catalog. The outcome — counts and per-row errors — is recorded as an
 * ImportHistory row (the "Upload History" feature), which doubles as the
 * reference_id for the ledger entries the import creates.
 */
class CsvInventoryImporter
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * Import the CSV at $path. $canViewCost gates whether the cost column is
     * honoured (Owner-only, mirroring the ItemRequest write guard). Returns the
     * persisted ImportHistory record describing the outcome.
     */
    public function import(string $path, ?string $filename, User $user, bool $canViewCost): ImportHistory
    {
        $importId = (string) Str::uuid();

        [$columnMap, $headerErrors] = $this->readHeaders($path);

        if ($headerErrors !== []) {
            return $this->record($importId, $user, $filename, self::MODE_FALLBACK, 'failed', 0, 0, 1, $headerErrors);
        }

        [$rows, $rowErrors] = $this->parseAndValidate($path, $columnMap, $canViewCost);

        $mode = $rows !== [] ? $rows[0]['mode'] : self::MODE_FALLBACK;
        $total = count($rows) + count($rowErrors);

        if ($total === 0) {
            return $this->record($importId, $user, $filename, $mode, 'failed', 0, 0, 1, [
                ['row' => null, 'messages' => ['The file has no data rows.']],
            ]);
        }

        if ($rowErrors !== []) {
            return $this->record($importId, $user, $filename, $mode, 'failed', $total, 0, count($rowErrors), $rowErrors);
        }

        try {
            DB::transaction(function () use ($rows, $user, $canViewCost, $importId) {
                $this->apply($rows, $user, $canViewCost, $importId);
            });
        } catch (ImportRowException $e) {
            return $this->record($importId, $user, $filename, $mode, 'failed', $total, 0, 1, [
                ['row' => $e->csvLine, 'messages' => [$e->getMessage()]],
            ]);
        }

        return $this->record($importId, $user, $filename, $mode, 'success', $total, $total, 0, []);
    }

    private const MODE_FALLBACK = InventoryCsvSchema::MODE_ADD;

    /**
     * Read and validate the header row. Returns [columnMap, errors] where
     * columnMap maps a canonical column name to its 0-based position, and a
     * non-empty errors list means the file is unusable (missing columns).
     *
     * @return array{0: array<string, int>, 1: list<array{row: null, messages: list<string>}>}
     */
    private function readHeaders(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [[], [['row' => null, 'messages' => ['The file could not be read.']]]];
        }

        $header = fgetcsv($handle, escape: '');
        fclose($handle);

        if ($header === false || $header === null) {
            return [[], [['row' => null, 'messages' => ['The file is empty.']]]];
        }

        $columnMap = [];
        foreach ($header as $index => $cell) {
            $name = InventoryCsvSchema::normalizeHeader((string) $cell);
            if (in_array($name, InventoryCsvSchema::COLUMNS, true) && ! isset($columnMap[$name])) {
                $columnMap[$name] = $index;
            }
        }

        $missing = array_diff(InventoryCsvSchema::REQUIRED, array_keys($columnMap));

        if ($missing !== []) {
            return [$columnMap, [[
                'row' => null,
                'messages' => ['The file is missing required column(s): '.implode(', ', $missing).'.'],
            ]]];
        }

        return [$columnMap, []];
    }

    /**
     * Parse every data row into a normalised structure and validate it. Returns
     * [rows, errors]; rows is only meaningful (and applied) when errors is empty.
     *
     * @param  array<string, int>  $columnMap
     * @return array{0: list<array<string, mixed>>, 1: list<array{row: int|null, messages: list<string>}>}
     */
    private function parseAndValidate(string $path, array $columnMap, bool $canViewCost): array
    {
        $handle = fopen($path, 'r');
        fgetcsv($handle, escape: ''); // discard header

        $rows = [];
        $errors = [];
        $seenSku = [];
        $seenBarcode = [];
        $line = 1; // header was line 1

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;

            // Skip fully blank rows (trailing newlines, spacer rows).
            if ($this->isBlankRow($cells)) {
                continue;
            }

            $get = fn (string $column): string => isset($columnMap[$column], $cells[$columnMap[$column]])
                ? trim((string) $cells[$columnMap[$column]])
                : '';

            $messages = [];

            $product = $get('product');
            $variantName = $get('variant');
            $sku = $get('sku');
            $barcode = $get('barcode');
            $category = $get('category');
            $unit = $get('unit');
            $description = $get('description');
            $sellingPriceRaw = $get('selling_price');
            $costRaw = $get('cost');
            $stockRaw = $get('stock');
            $thresholdRaw = $get('low_stock_threshold');
            $mode = InventoryCsvSchema::normalizeMode($get('mode'));

            foreach (['product' => $product, 'sku' => $sku] as $label => $value) {
                if ($value === '') {
                    $messages[] = ucfirst($label).' is required.';
                }
            }

            if (! $this->isValidMoney($sellingPriceRaw)) {
                $messages[] = 'Selling price must be a number with up to 2 decimals and no negative value.';
            }

            if ($costRaw !== '' && ! $this->isValidMoney($costRaw)) {
                $messages[] = 'Cost must be a number with up to 2 decimals and no negative value.';
            }

            $stock = null;
            if (! $this->isInteger($stockRaw)) {
                $messages[] = 'Stock must be a whole number.';
            } else {
                $stock = (int) $stockRaw;
                if ($mode === InventoryCsvSchema::MODE_REPLACE && $stock < 0) {
                    $messages[] = 'Stock cannot be negative when replacing the stock count.';
                }
            }

            $threshold = null;
            if ($thresholdRaw !== '') {
                if (! $this->isInteger($thresholdRaw) || (int) $thresholdRaw < 0) {
                    $messages[] = 'Low stock threshold must be a whole number of 0 or more.';
                } else {
                    $threshold = (int) $thresholdRaw;
                }
            }

            if (! in_array($mode, InventoryCsvSchema::MODES, true)) {
                $messages[] = 'Mode must be "'.InventoryCsvSchema::MODE_REPLACE.'" or "'.InventoryCsvSchema::MODE_ADD.'".';
            }

            // In-file duplicate SKU/barcode: each brand-new colliding row passes a
            // DB check individually, so reject collisions within the file itself.
            if ($sku !== '') {
                $skuKey = mb_strtolower($sku);
                if (isset($seenSku[$skuKey])) {
                    $messages[] = 'SKU "'.$sku.'" appears on more than one row.';
                }
                $seenSku[$skuKey] = true;
            }

            if ($barcode !== '') {
                $barcodeKey = mb_strtolower($barcode);
                if (isset($seenBarcode[$barcodeKey])) {
                    $messages[] = 'Barcode "'.$barcode.'" appears on more than one row.';
                }
                $seenBarcode[$barcodeKey] = true;

                // Barcode collides with a different existing variant (matched by
                // SKU). Uniqueness spans archived variants too.
                if ($sku !== '' && $this->barcodeTakenByAnotherVariant($barcode, $sku)) {
                    $messages[] = 'Barcode "'.$barcode.'" is already used by another product.';
                }
            }

            if ($messages !== []) {
                $errors[] = ['row' => $line, 'messages' => $messages];

                continue;
            }

            $rows[] = [
                'line' => $line,
                'category' => $category === '' ? null : $category,
                'product' => $product,
                // Blank variant name -> null: a "no variant" item's single
                // variant simply references the parent product.
                'variant' => $variantName === '' ? null : $variantName,
                'sku' => $sku,
                'barcode' => $barcode === '' ? null : $barcode,
                'selling_price' => $sellingPriceRaw,
                'cost' => ($canViewCost && $costRaw !== '') ? $costRaw : null,
                'cost_present' => $canViewCost && $costRaw !== '',
                'stock' => $stock,
                'unit' => $unit === '' ? null : $unit,
                'description' => $description === '' ? null : $description,
                'low_stock_threshold' => $threshold,
                'mode' => $mode,
            ];
        }

        fclose($handle);

        return [$rows, $errors];
    }

    /**
     * Apply validated rows. Runs inside a transaction so a NegativeInventoryException
     * (re-thrown as ImportRowException) rolls the whole batch back.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function apply(array $rows, User $user, bool $canViewCost, string $importId): void
    {
        $categoryCache = [];
        $itemCache = [];

        foreach ($rows as $row) {
            $variant = Variant::where('sku', $row['sku'])->first();

            if ($variant === null) {
                $categoryId = $this->resolveCategoryId($row['category'], $categoryCache);
                $item = $this->resolveItem($row, $categoryId, $itemCache);
                $variant = new Variant(['item_id' => $item->getKey()]);
            } else {
                // Update the parent item's descriptive fields in place. Product
                // name and category are left untouched so an item shared by
                // variants outside this file is never silently renamed or moved.
                $variant->item->update([
                    'unit' => $row['unit'] ?? $variant->item->unit,
                    'description' => $row['description'],
                ]);
            }

            $variant->sku = $row['sku'];
            $variant->name = $row['variant'];
            $variant->barcode = $row['barcode'];
            $variant->selling_price = $row['selling_price'];

            if ($row['low_stock_threshold'] !== null) {
                $variant->low_stock_threshold = $row['low_stock_threshold'];
            } elseif (! $variant->exists) {
                $variant->low_stock_threshold = 0;
            }

            if ($canViewCost && $row['cost_present']) {
                $variant->cost = $row['cost'];
            }

            $variant->save();

            $this->applyStock($variant, $row, $user, $importId);
        }
    }

    /**
     * Translate the row's mode + stock value into a signed delta and record it
     * through the ledger. Zero-delta rows write nothing (no-op, not an error).
     *
     * @param  array<string, mixed>  $row
     */
    private function applyStock(Variant $variant, array $row, User $user, string $importId): void
    {
        $delta = $row['mode'] === InventoryCsvSchema::MODE_REPLACE
            ? $row['stock'] - $this->ledger->currentStock($variant)
            : $row['stock'];

        if ($delta === 0) {
            return;
        }

        try {
            $this->ledger->record(
                variant: $variant,
                quantity: $delta,
                type: 'stock_adjustment',
                saleType: null,
                referenceType: 'csv_import',
                referenceId: $importId,
                performedBy: $user,
                notes: 'CSV import',
            );
        } catch (NegativeInventoryException $e) {
            throw new ImportRowException($row['line'], $e->getMessage());
        }
    }

    /**
     * @param  array<string, string>  $cache  name-lowercase => category id
     */
    private function resolveCategoryId(?string $name, array &$cache): ?string
    {
        if ($name === null) {
            return null;
        }

        $key = mb_strtolower($name);

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $category = Category::whereRaw('lower(name) = ?', [$key])->first()
            ?? Category::create(['name' => $name]);

        return $cache[$key] = $category->getKey();
    }

    /**
     * Find-or-create the Item a brand-new variant attaches to, matched by name
     * (case-insensitively). Item names are globally unique, so a row whose
     * product name already exists resolves to that item — regardless of the
     * row's category — rather than attempting a duplicate that the unique index
     * would reject. Cached so several new-variant rows of one product share a
     * single item.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, Item>  $cache
     */
    private function resolveItem(array $row, ?string $categoryId, array &$cache): Item
    {
        $key = mb_strtolower($row['product']);

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $item = Item::whereRaw('lower(name) = ?', [$key])
            ->first()
            ?? Item::create([
                'name' => $row['product'],
                'unit' => $row['unit'] ?? 'pc',
                'category_id' => $categoryId,
                'description' => $row['description'],
            ]);

        return $cache[$key] = $item;
    }

    private function barcodeTakenByAnotherVariant(string $barcode, string $sku): bool
    {
        return Variant::where('barcode', $barcode)
            ->where('sku', '!=', $sku)
            ->exists();
    }

    /**
     * @param  list<string>  $cells
     */
    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function isValidMoney(string $value): bool
    {
        return $value !== '' && preg_match('/^\d+(\.\d{1,2})?$/', $value) === 1;
    }

    private function isInteger(string $value): bool
    {
        return $value !== '' && preg_match('/^[+-]?\d+$/', $value) === 1;
    }

    /**
     * @param  list<array{row: int|null, messages: list<string>}>  $errors
     */
    private function record(
        string $id,
        User $user,
        ?string $filename,
        string $mode,
        string $status,
        int $totalRows,
        int $successCount,
        int $errorCount,
        array $errors,
    ): ImportHistory {
        // Set the id directly (it is guarded, so it must not go through mass
        // assignment) so it matches the reference_id already written onto the
        // import's ledger rows. HasUuids leaves a pre-set key untouched.
        $import = new ImportHistory([
            'user_id' => $user->getKey(),
            'filename' => $filename,
            'mode' => $mode,
            'status' => $status,
            'total_rows' => $totalRows,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors === [] ? null : $errors,
        ]);
        $import->id = $id;
        $import->save();

        return $import;
    }
}
