<?php

namespace App\Support\Inventory;

/**
 * Single source of truth for the inventory CSV shape (Phase 7). The template
 * download, the export, and the importer all read their columns from here so
 * the three stay in lockstep.
 *
 * Column order below is the canonical order for generated files. Cost is only
 * emitted for the Owner (see the controllers), but it keeps a fixed slot so the
 * layout is stable whether or not it is present.
 */
final class InventoryCsvSchema
{
    /** Every recognised column, in canonical order. */
    public const COLUMNS = [
        'category',
        'product',
        'variant',
        'sku',
        'barcode',
        'selling_price',
        'cost',
        'stock',
        'unit',
        'description',
        'low_stock_threshold',
        'mode',
    ];

    /** Columns that must carry a value on every data row. */
    public const REQUIRED = [
        'product',
        'sku',
        'selling_price',
        'stock',
        'mode',
    ];

    /** Allowed per-row stock modes. */
    public const MODE_REPLACE = 'replace_stock_count';

    public const MODE_ADD = 'add_to_current_stock';

    public const MODES = [self::MODE_REPLACE, self::MODE_ADD];

    /**
     * Header row for a generated file. Cost is dropped for non-Owners so the
     * value never even leaves the server.
     *
     * @return list<string>
     */
    public static function headers(bool $includeCost = true): array
    {
        return array_values(array_filter(
            self::COLUMNS,
            fn (string $column) => $includeCost || $column !== 'cost',
        ));
    }

    /**
     * A single illustrative row for the downloadable template, keyed by column.
     *
     * @return array<string, string>
     */
    public static function exampleRow(): array
    {
        return [
            'category' => 'Beverages',
            'product' => 'Bottled Water',
            'variant' => '500ml',
            'sku' => 'WATER-500',
            'barcode' => '4801234567890',
            'selling_price' => '15.00',
            'cost' => '9.50',
            'stock' => '100',
            'unit' => 'pc',
            'description' => 'Purified drinking water',
            'low_stock_threshold' => '12',
            'mode' => self::MODE_REPLACE,
        ];
    }

    /**
     * Normalise a raw header cell to its canonical key: trim, lowercase, and
     * fold spaces/hyphens to underscores so "Selling Price", "selling-price"
     * and "selling_price" all match.
     */
    public static function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));

        return (string) preg_replace('/[\s-]+/', '_', $header);
    }

    /**
     * Normalise a raw mode cell the same way ("Replace Stock Count" ->
     * "replace_stock_count"). Returns the normalised string (may be invalid).
     */
    public static function normalizeMode(string $mode): string
    {
        return self::normalizeHeader($mode);
    }
}
