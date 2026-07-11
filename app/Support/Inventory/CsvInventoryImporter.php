<?php

namespace App\Support\Inventory;

use App\Exceptions\ImportRowException;
use App\Exceptions\NegativeInventoryException;
use App\Models\Category;
use App\Models\ImportHistory;
use App\Models\Item;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

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
 *
 * Semantics the importer enforces (mirroring the item form):
 *   - SKUs are mandatory and canonically UPPERCASE — lowercase input is
 *     accepted and normalized, so "water-500" matches a stored "WATER-500".
 *   - Item names are immutable: a row whose product cell disagrees with the
 *     item its SKU belongs to is an error, never a silent no-op. Same for a
 *     category cell that would move an existing product.
 *   - Blank optional cells never wipe data: on a matched variant they keep the
 *     current value; only on a brand-new one do they mean "default/absent".
 */
class CsvInventoryImporter
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * Import the CSV at $path. $canViewCost gates whether the cost column is
     * honoured (Owner-only, mirroring the ItemRequest write guard). Returns the
     * persisted ImportHistory record describing the outcome.
     *
     * Never throws for a bad file: any unexpected failure is reported and
     * recorded as a failed import so the user always gets an Upload History
     * entry instead of an error page.
     */
    public function import(string $path, ?string $filename, User $user, bool $canViewCost): ImportHistory
    {
        $importId = (string) Str::uuid();

        try {
            return $this->run($path, $filename, $user, $canViewCost, $importId);
        } catch (Throwable $e) {
            report($e);
        }

        // Outside the try so a failure while recording the failure propagates
        // instead of being swallowed.
        return $this->record($importId, $user, $filename, self::MODE_FALLBACK, 'failed', 0, 0, 1, [
            ['row' => null, 'messages' => ['Something went wrong while processing the file. No changes were saved.']],
        ]);
    }

    private const MODE_FALLBACK = InventoryCsvSchema::MODE_ADD;

    /** ImportHistory mode value for a file whose rows use both modes. */
    private const MODE_MIXED = 'mixed';

    /** whereIn/placeholder chunk size, safely below SQLite's variable limit. */
    private const LOOKUP_CHUNK = 500;

    private function run(string $path, ?string $filename, User $user, bool $canViewCost, string $importId): ImportHistory
    {
        // The upload is capped at 5 MB (InventoryImportRequest), so reading it
        // whole is fine. Reject non-UTF-8 files up front: their bytes would
        // otherwise poison error messages and the JSON errors column.
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return $this->record($importId, $user, $filename, self::MODE_FALLBACK, 'failed', 0, 0, 1, [
                ['row' => null, 'messages' => ['The file could not be read.']],
            ]);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            return $this->record($importId, $user, $filename, self::MODE_FALLBACK, 'failed', 0, 0, 1, [
                ['row' => null, 'messages' => ['The file must be UTF-8 encoded. Re-save it as "CSV UTF-8" and upload again.']],
            ]);
        }

        [$columnMap, $headerErrors] = $this->readHeaders($path);

        if ($headerErrors !== []) {
            return $this->record($importId, $user, $filename, self::MODE_FALLBACK, 'failed', 0, 0, 1, $headerErrors);
        }

        [$rows, $rowErrors] = $this->parseAndValidate($path, $columnMap, $canViewCost);

        $modes = array_values(array_unique(array_column($rows, 'mode')));
        $mode = match (true) {
            $modes === [] => self::MODE_FALLBACK,
            count($modes) === 1 => $modes[0],
            default => self::MODE_MIXED,
        };

        $total = count($rows) + count($rowErrors);

        if ($total === 0) {
            return $this->record($importId, $user, $filename, $mode, 'failed', 0, 0, 1, [
                ['row' => null, 'messages' => ['The file has no data rows.']],
            ]);
        }

        // Once every row is individually valid, validate the resulting catalog
        // shape against the DB (archived matches, immutable names/categories,
        // no-variant vs. named model).
        if ($rowErrors === []) {
            $rowErrors = $this->validateCatalogShape($rows);
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
     * Per-field maximum lengths, mirroring the item/category form rules so the
     * import cannot create values the UI would reject.
     */
    private const MAX_LENGTHS = [
        'Product' => 255,
        'Variant' => 255,
        'SKU' => 255,
        'Barcode' => 255,
        'Category' => 255,
        'Unit' => 50,
        'Description' => 1000,
    ];

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

        if ($handle === false) {
            return [[], [['row' => null, 'messages' => ['The file could not be read.']]]];
        }

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
            // SKUs are canonically UPPERCASE; normalizing here means every
            // downstream lookup, duplicate check and save uses the stored form.
            $sku = mb_strtoupper($get('sku'));
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

            foreach ([
                'Product' => $product,
                'Variant' => $variantName,
                'SKU' => $sku,
                'Barcode' => $barcode,
                'Category' => $category,
                'Unit' => $unit,
                'Description' => $description,
            ] as $label => $value) {
                if (mb_strlen($value) > self::MAX_LENGTHS[$label]) {
                    $messages[] = $label.' must be '.self::MAX_LENGTHS[$label].' characters or fewer.';
                }
            }

            if (! $this->isValidMoney($sellingPriceRaw)) {
                $messages[] = 'Selling price must be a number between 0 and 9999999999.99 with up to 2 decimals.';
            }

            if ($costRaw !== '' && ! $this->isValidMoney($costRaw)) {
                $messages[] = 'Cost must be a number between 0 and 9999999999.99 with up to 2 decimals.';
            }

            $stock = null;
            if (! $this->isInteger($stockRaw)) {
                $messages[] = 'Stock must be a whole number between -999999999 and 999999999.';
            } else {
                $stock = (int) $stockRaw;
                if ($mode === InventoryCsvSchema::MODE_REPLACE && $stock < 0) {
                    $messages[] = 'Stock cannot be negative when replacing the stock count.';
                }
            }

            $threshold = null;
            if ($thresholdRaw !== '') {
                if (! $this->isInteger($thresholdRaw) || (int) $thresholdRaw < 0) {
                    $messages[] = 'Low stock threshold must be a whole number between 0 and 999999999.';
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
                if (isset($seenSku[$sku])) {
                    $messages[] = 'SKU "'.$sku.'" appears on more than one row.';
                }
                $seenSku[$sku] = true;
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
                // Blank variant name -> null: "keep the current name" for an
                // existing variant, unnamed ("no variant") for a new one.
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
     * DB-backed validation of the catalog shape the file would produce, run only
     * once every row is individually valid. Enforces the same invariants as the
     * individual item form (ItemRequest) so a bulk import can't create states the
     * UI forbids:
     *   - a row must not match an archived variant (by SKU) or an archived item
     *     (by product name) — the user must restore it first;
     *   - item names are immutable and items never move category via import, so
     *     a product or category cell that disagrees with the SKU's item is an
     *     error, never a silent no-op;
     *   - a brand-new item cannot be filed under an archived category;
     *   - rows describing the same product must agree on its category, unit and
     *     description (no silent first-row-wins);
     *   - an item is either a single unnamed "no variant" product or has every
     *     variant named; the unnamed+named (or multiple-unnamed) mix is rejected.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{row: int|null, messages: list<string>}>
     */
    private function validateCatalogShape(array $rows): array
    {
        $errors = [];

        // Existing variants that any row matches by SKU (exact match — both
        // sides are canonically uppercase). Includes archived ones so we can
        // reject them explicitly.
        $skus = array_values(array_unique(array_column($rows, 'sku')));
        $variantsBySku = collect(array_chunk($skus, self::LOOKUP_CHUNK))
            ->flatMap(fn (array $chunk) => Variant::with(['item:id,name,archived_at,category_id', 'item.category:id,name'])
                ->whereIn('sku', $chunk)
                ->get(['id', 'sku', 'name', 'item_id', 'archived_at']))
            ->keyBy('sku');

        // Existing items referenced by product name (case-insensitive), used to
        // resolve new-SKU rows and to reject archived-name matches.
        $itemsByName = $this->itemsByLowerName(array_column($rows, 'product'));

        // Existing categories referenced by name, to reject archived matches
        // for brand-new items. (Category names have no unique index; the
        // importer has always matched them case-insensitively.)
        $categoriesByName = $this->categoriesByLowerName(array_column($rows, 'category'));

        // Group rows by the item they will end up on, so we can check the final
        // variant naming per item. Key: "item:<id>" for an existing item,
        // "new:<lower name>" for one this file would create.
        $groups = [];

        foreach ($rows as $row) {
            $existing = $variantsBySku->get($row['sku']);

            if ($existing !== null) {
                if ($existing->archived_at !== null) {
                    $errors[] = ['row' => $row['line'], 'messages' => [
                        'SKU "'.$row['sku'].'" belongs to an archived variant. Restore it from Inventory before importing.',
                    ]];

                    continue;
                }

                // The variant is active but its parent item may be archived
                // (archiving an item does not cascade to its variants).
                if ($existing->item !== null && $existing->item->archived_at !== null) {
                    $errors[] = ['row' => $row['line'], 'messages' => [
                        'SKU "'.$row['sku'].'" belongs to an archived product. Restore it from Inventory before importing.',
                    ]];

                    continue;
                }

                // Item names are immutable: a product cell that disagrees with
                // the SKU's item is a mistake, not a rename request.
                if ($existing->item !== null && mb_strtolower($row['product']) !== mb_strtolower($existing->item->name)) {
                    $errors[] = ['row' => $row['line'], 'messages' => [
                        'SKU "'.$row['sku'].'" belongs to "'.$existing->item->name.'", but this row names the product "'
                        .$row['product'].'". The import can\'t rename products — fix the product cell.',
                    ]];

                    continue;
                }

                if ($existing->item !== null && ($message = $this->categoryMismatchMessage($row, $existing->item)) !== null) {
                    $errors[] = ['row' => $row['line'], 'messages' => [$message]];

                    continue;
                }

                // Stash the match so the naming check below can substitute the
                // variant's current name for a blank cell.
                $row['existing'] = $existing;

                $groups['item:'.$existing->item_id]['item'] ??= $existing->item;
                $groups['item:'.$existing->item_id]['rows'][] = $row;

                continue;
            }

            // Brand-new SKU: resolve the item it would attach to by name.
            $item = $itemsByName->get(mb_strtolower($row['product']));

            if ($item !== null && $item->archived_at !== null) {
                $errors[] = ['row' => $row['line'], 'messages' => [
                    'Product "'.$row['product'].'" matches an archived item. Restore it from Inventory before importing.',
                ]];

                continue;
            }

            if ($item !== null && ($message = $this->categoryMismatchMessage($row, $item)) !== null) {
                $errors[] = ['row' => $row['line'], 'messages' => [$message]];

                continue;
            }

            // Brand-new item: it must not be filed under an archived category.
            if ($item === null && $row['category'] !== null) {
                $categoryMatch = $categoriesByName->get(mb_strtolower($row['category']));

                if ($categoryMatch !== null && $categoryMatch->archived_at !== null) {
                    $errors[] = ['row' => $row['line'], 'messages' => [
                        'Category "'.$categoryMatch->name.'" is archived. Restore it from Inventory before importing.',
                    ]];

                    continue;
                }
            }

            $key = $item !== null ? 'item:'.$item->getKey() : 'new:'.mb_strtolower($row['product']);
            $groups[$key]['item'] ??= $item; // null for a to-be-created item
            $groups[$key]['rows'][] = $row;
        }

        if ($errors !== []) {
            return $errors; // archived/mismatch rows already reject the whole file
        }

        // Existing active variants of the items the file touches, so an item
        // shared with variants outside the file is judged on its full shape.
        $existingItemIds = [];
        foreach ($groups as $group) {
            if (($group['item'] ?? null) !== null) {
                $existingItemIds[] = $group['item']->getKey();
            }
        }

        $activeByItem = collect(array_chunk($existingItemIds, self::LOOKUP_CHUNK))
            ->flatMap(fn (array $chunk) => Variant::active()
                ->whereIn('item_id', $chunk)
                ->get(['id', 'sku', 'name', 'item_id']))
            ->groupBy('item_id');

        foreach ($groups as $group) {
            $item = $group['item'] ?? null;
            $rowsForItem = $group['rows'];
            $fileSkus = array_column($rowsForItem, 'sku');

            // Rows describing one product must agree on its descriptive fields
            // — a conflict is a mistake, never a silent first-row-wins.
            foreach ($this->conflictingFieldErrors($rowsForItem) as $error) {
                $errors[] = $error;
            }

            // Final variant names = file rows (upsert by SKU; a blank cell keeps
            // the matched variant's current name) + this item's other active
            // variants not present in the file.
            $names = array_map(fn (array $row) => $this->effectiveVariantName($row), $rowsForItem);

            $itemActive = $item !== null ? ($activeByItem[$item->getKey()] ?? collect()) : collect();
            foreach ($itemActive as $variant) {
                if (! in_array($variant->sku, $fileSkus, true)) {
                    $names[] = $variant->name;
                }
            }

            $unnamed = array_values(array_filter(
                $names,
                fn ($name) => $name === null || trim((string) $name) === ''
            ));

            if (count($names) > 1 && $unnamed !== []) {
                $errors[] = $this->variantMixError($group, $rowsForItem);
            }
        }

        return $errors;
    }

    /**
     * The variant name a row would leave in place: the cell value, or — when the
     * cell is blank and the row matched an existing variant — that variant's
     * current name. Blank on a brand-new variant stays null (unnamed).
     *
     * @param  array<string, mixed>  $row
     */
    private function effectiveVariantName(array $row): ?string
    {
        return $row['variant'] ?? ($row['existing'] ?? null)?->name;
    }

    /**
     * Import cannot rename or re-file existing products: a non-blank category
     * cell must match the item's current category (case-insensitively). Returns
     * the row error message, or null when the row is consistent.
     *
     * @param  array<string, mixed>  $row
     */
    private function categoryMismatchMessage(array $row, Item $item): ?string
    {
        if ($row['category'] === null) {
            return null;
        }

        $current = $item->category?->name;

        if ($current !== null && mb_strtolower($row['category']) === mb_strtolower($current)) {
            return null;
        }

        return 'This row places "'.$item->name.'" in category "'.$row['category'].'", but it is currently '
            .($current === null ? 'uncategorized' : 'in "'.$current.'"')
            .'. The import can\'t move products between categories — leave the category cell blank, or change it in Inventory.';
    }

    /**
     * Rows describing the same product must agree on its category, unit and
     * description. Only non-blank cells participate (blank means "no opinion"),
     * and one error per conflicting field is reported.
     *
     * @param  list<array<string, mixed>>  $rowsForItem
     * @return list<array{row: int|null, messages: list<string>}>
     */
    private function conflictingFieldErrors(array $rowsForItem): array
    {
        $errors = [];

        foreach (['category', 'unit', 'description'] as $field) {
            $first = null;

            foreach ($rowsForItem as $row) {
                $value = $row[$field];

                if ($value === null) {
                    continue;
                }

                // Categories match case-insensitively everywhere else, so a
                // case-only difference is not a conflict.
                $comparable = $field === 'category' ? mb_strtolower($value) : $value;

                if ($first === null) {
                    $first = ['value' => $value, 'comparable' => $comparable, 'line' => $row['line']];

                    continue;
                }

                if ($comparable !== $first['comparable']) {
                    $errors[] = ['row' => $row['line'], 'messages' => [
                        'Rows '.$first['line'].' and '.$row['line'].' both describe product "'.$rowsForItem[0]['product']
                        .'" but disagree on '.$field.' ("'.$first['value'].'" vs "'.$value.'"). '
                        .'Make them match, or leave all but one blank.',
                    ]];

                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Build the row-attributed error for an item that would end up mixing an
     * unnamed (no-variant) row with named ones. The blank variant can come from
     * either a file row or an existing unnamed variant not present in the file,
     * and the item may be brand-new or already recorded — each needs a different,
     * plain-language explanation, so pick the one that matches the situation.
     *
     * @param  array{item: Item|null, rows: list<array<string, mixed>>}  $group
     * @param  list<array<string, mixed>>  $rowsForItem
     * @return array{row: int|null, messages: list<string>}
     */
    private function variantMixError(array $group, array $rowsForItem): array
    {
        $item = $group['item'] ?? null;
        $product = $rowsForItem[0]['product'];

        // File rows for this product whose effective variant name is blank (a
        // blank cell that keeps an existing variant's name does not count).
        $blankRows = array_values(array_filter(
            $rowsForItem,
            fn (array $row) => $this->effectiveVariantName($row) === null
        ));

        // No blank row in the file means the blank comes from an existing unnamed
        // ("no variant") variant that the file leaves untouched while adding named
        // ones. The fix lives in Inventory, not the file.
        if ($blankRows === []) {
            return ['row' => $rowsForItem[0]['line'], 'messages' => [
                'Product "'.$product.'" already exists as a single product with no variants. '
                .'To give it variants, open it in Inventory, name its existing variant, then re-import these rows.',
            ]];
        }

        $line = $blankRows[0]['line'];

        // Brand-new product (name/SKU not recorded yet) whose only rows are blank:
        // these are the same item repeated, differing solely by SKU. This is the
        // common "duplicate rows" mistake — say so plainly.
        $allBlank = count($blankRows) === count($rowsForItem);
        if ($item === null && $allBlank && count($blankRows) > 1) {
            $lines = implode(', ', array_map(fn (array $row) => $row['line'], $blankRows));

            return ['row' => $line, 'messages' => [
                'Rows '.$lines.' all add a new product named "'.$product.'" with no variant, '
                .'so they describe the same item. Give each row a different variant name to keep '
                .'them as separate variants, or remove the duplicate row.',
            ]];
        }

        // Otherwise a blank row sits alongside named variants (named rows in the
        // file, or existing named variants on the item). Name that specific row.
        $sku = $blankRows[0]['sku'];

        return ['row' => $line, 'messages' => [
            'Product "'.$product.'" has a row with no variant name (SKU "'.$sku.'") together with '
            .'named variant rows. A product either has no variants (one blank row) or names every '
            .'row — give that row a variant name, or remove it.',
        ]];
    }

    /**
     * Fetch items whose lower(name) matches any of the given product names,
     * keyed by lower(name), chunked to stay below SQLite's variable limit.
     * Empty input yields an empty collection.
     *
     * @param  list<string>  $productNames
     * @return Collection<string, Item>
     */
    private function itemsByLowerName(array $productNames): Collection
    {
        $keys = array_values(array_unique(array_map(
            fn (string $name) => mb_strtolower($name),
            $productNames
        )));

        return collect(array_chunk($keys, self::LOOKUP_CHUNK))
            ->flatMap(function (array $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));

                return Item::query()
                    ->with('category:id,name')
                    ->whereRaw('lower(name) in ('.$placeholders.')', $chunk)
                    ->get(['id', 'name', 'archived_at', 'category_id']);
            })
            ->keyBy(fn (Item $item) => mb_strtolower($item->name));
    }

    /**
     * Fetch categories whose lower(name) matches any of the given names, keyed
     * by lower(name). Null entries (blank cells) are skipped.
     *
     * @param  list<string|null>  $categoryNames
     * @return Collection<string, Category>
     */
    private function categoriesByLowerName(array $categoryNames): Collection
    {
        $keys = array_values(array_unique(array_map(
            fn (string $name) => mb_strtolower($name),
            array_filter($categoryNames, fn ($name) => $name !== null)
        )));

        return collect(array_chunk($keys, self::LOOKUP_CHUNK))
            ->flatMap(function (array $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));

                return Category::query()
                    ->whereRaw('lower(name) in ('.$placeholders.')', $chunk)
                    ->get(['id', 'name', 'archived_at']);
            })
            ->keyBy(fn (Category $category) => mb_strtolower($category->name));
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
                $item = $this->resolveItem($row, $itemCache, $categoryCache);
                $variant = new Variant(['item_id' => $item->getKey()]);
                $variant->sku = $row['sku'];
                $variant->name = $row['variant'];
                $variant->barcode = $row['barcode'];
            } else {
                $item = $variant->item;

                if ($row['variant'] !== null) {
                    $variant->name = $row['variant'];
                }

                if ($row['barcode'] !== null) {
                    $variant->barcode = $row['barcode'];
                }
            }

            // Blank cells never wipe data: only non-blank values touch the
            // item, whether the row matched an existing variant or attached a
            // new one to it. (Product name and category are validated to
            // match, so they are never written here.) On an item this file
            // just created the values are already in place, so this is a no-op.
            $itemUpdates = array_filter([
                'unit' => $row['unit'],
                'description' => $row['description'],
            ], fn ($value) => $value !== null);

            if ($itemUpdates !== []) {
                $item->update($itemUpdates);
            }

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
     * product name already exists resolves to that item — validation has already
     * confirmed the row's category agrees with it. The row's category is only
     * resolved (and possibly created) when the item itself is created, so an
     * import never leaves an orphan category behind. Cached so several
     * new-variant rows of one product share a single item.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, Item>  $itemCache
     * @param  array<string, string>  $categoryCache
     */
    private function resolveItem(array $row, array &$itemCache, array &$categoryCache): Item
    {
        $key = mb_strtolower($row['product']);

        if (isset($itemCache[$key])) {
            return $itemCache[$key];
        }

        $item = Item::active()
            ->whereRaw('lower(name) = ?', [$key])
            ->first()
            ?? Item::create([
                'name' => $row['product'],
                'unit' => $row['unit'] ?? 'pc',
                'category_id' => $this->resolveCategoryId($row['category'], $categoryCache),
                'description' => $row['description'],
            ]);

        return $itemCache[$key] = $item;
    }

    private function barcodeTakenByAnotherVariant(string $barcode, string $sku): bool
    {
        // whereNull guards the (historical) SKU-less variant: `sku != ?` is
        // NULL — not true — for those rows, which would hide their barcodes
        // from this check and blow up on the unique index at save time.
        return Variant::where('barcode', $barcode)
            ->where(fn ($query) => $query->whereNull('sku')->orWhere('sku', '!=', $sku))
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
        // Integer part capped at 10 digits: <= 9999999999.99, mirroring the
        // item form's max. Checked before any cast so nothing can saturate.
        return $value !== '' && preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value) === 1;
    }

    private function isInteger(string $value): bool
    {
        // At most 9 digits (|value| <= 999,999,999): far beyond any real stock
        // count and safely inside PHP's int range, so (int) casts are exact.
        return $value !== '' && preg_match('/^[+-]?\d{1,9}$/', $value) === 1;
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
