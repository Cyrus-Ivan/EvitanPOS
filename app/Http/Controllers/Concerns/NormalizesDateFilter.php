<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Carbon;

/**
 * Shared query-string date parsing for filterable index pages (extracted in
 * Phase 18 — the same helper was copy-pasted across five controllers, where
 * the copies could drift).
 */
trait NormalizesDateFilter
{
    /**
     * Parse a date filter param, discarding anything unparseable, else null.
     */
    private function normalizeDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
