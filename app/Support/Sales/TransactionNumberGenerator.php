<?php

namespace App\Support\Sales;

use App\Models\Transaction;
use Illuminate\Support\Carbon;

/**
 * Builds the human-readable transaction/receipt number for a sale.
 *
 * Format: YYYYMMDD-HHMMSS-000001 — the calendar date, the wall-clock time of
 * the sale, and a zero-padded sequence that resets every day. The receipt
 * number equals the transaction number (BRAINSTORM.md).
 *
 * The daily sequence — not the time — is what makes a number unique, so two
 * sales in the same second still differ. Concurrency safety does not live here:
 * the caller wraps generate + insert in a bounded retry and leans on the
 * `transaction_number` UNIQUE index as the integrity backstop (there is no
 * counter row to lock; SQLite serializes writers anyway).
 */
class TransactionNumberGenerator
{
    public function next(): string
    {
        $now = Carbon::now();
        $datePrefix = $now->format('Ymd');
        $time = $now->format('His');

        $sequence = Transaction::query()
            ->where('transaction_number', 'like', $datePrefix.'-%')
            ->count() + 1;

        return sprintf('%s-%s-%06d', $datePrefix, $time, $sequence);
    }
}
