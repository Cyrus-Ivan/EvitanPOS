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
 * The sequence continues from today's highest issued number (MAX over the
 * number itself, not a row count — a count would collide for the rest of the
 * day if a row were ever lost; MAX self-heals). Concurrency safety does not
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
        $prefix = 'RET-'.today()->format('Ymd').'-';

        // Fixed-width zero padding makes the lexicographic MAX the numeric MAX.
        $highest = (string) SaleReturn::query()
            ->where('return_number', 'like', $prefix.'%')
            ->max('return_number');

        $sequence = (int) substr($highest, -self::PAD_WIDTH) + 1;

        return sprintf('%s%0'.self::PAD_WIDTH.'d', $prefix, $sequence);
    }
}
