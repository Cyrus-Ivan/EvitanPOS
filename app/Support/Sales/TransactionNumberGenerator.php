<?php

namespace App\Support\Sales;

use App\Models\Transaction;

/**
 * Builds the transaction/receipt number for a sale.
 *
 * Format: a continuous, zero-padded running serial — 0000000001, 0000000002, …
 * It never resets (no date component; the timestamp lives in `created_at`) and
 * has no gaps across committed sales. This is the shape a BIR-registered CRM/POS
 * document serial must take — unique, consecutive, non-resetting — so this one
 * number serves as both the internal transaction id and the customer-facing
 * receipt number.
 *
 * The sequence is derived from the row count, so concurrency safety does not
 * live here: the caller wraps generate + insert in a bounded retry and leans on
 * the `transaction_number` UNIQUE index as the integrity backstop (there is no
 * counter row to lock; SQLite serializes writers anyway). Committed serials stay
 * gapless because a rolled-back sale never claims a number.
 *
 * TODO(compliance): a real BIR Authority-To-Print (ATP) range needs a
 * configurable series prefix and starting serial (per-registration). Not built
 * here — the format is range-ready but starts at 1 with no prefix.
 */
class TransactionNumberGenerator
{
    /** Zero-pad width of the running serial. */
    private const PAD_WIDTH = 10;

    public function next(): string
    {
        $sequence = Transaction::query()->count() + 1;

        return sprintf('%0'.self::PAD_WIDTH.'d', $sequence);
    }
}
