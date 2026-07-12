<?php

namespace App\Support\Sales;

use App\Models\SaleReturn;

/**
 * Builds the number for an after-sales event (return / defect).
 *
 * Format: RET-YYYYMMDD-000001 — a per-day sequence that resets each day, mirroring
 * the human-readable receipt shape. Unlike the never-resetting transaction serial,
 * returns are scoped and counted per calendar day.
 *
 * The sequence is derived from today's row count, so concurrency safety does not
 * live here: the caller wraps generate + insert in a bounded retry and leans on
 * the `return_number` UNIQUE index as the integrity backstop (SQLite serializes
 * writers anyway).
 */
class SaleReturnNumberGenerator
{
    /** Zero-pad width of the daily sequence. */
    private const PAD_WIDTH = 6;

    public function next(): string
    {
        $sequence = SaleReturn::query()->whereDate('created_at', today())->count() + 1;

        return sprintf('RET-%s-%0'.self::PAD_WIDTH.'d', today()->format('Ymd'), $sequence);
    }
}
