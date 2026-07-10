<?php

namespace App\Support\Inventory;

use App\Exceptions\NegativeInventoryException;
use App\Models\InventoryLedger;
use App\Models\StockSnapshot;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for all inventory movement (CLAUDE.md: "There is no
 * stock-adjustment table" — every sale, return, defect, manual adjustment and
 * CSV import is expressed as an append-only inventory_ledger entry).
 *
 * Each write is atomic: it locks the variant's stock snapshot, computes the
 * resulting on-hand, refuses to go negative, appends the ledger row, and
 * updates the snapshot. Later phases (POS, Returns, CSV import) funnel through
 * record() so this invariant lives in exactly one place.
 */
class StockLedger
{
    /**
     * Append one ledger entry for the variant and update its stock snapshot.
     *
     * @param  int  $quantity  Signed movement (+ adds stock, - removes). Defect
     *                         audit rows pass 0 (stock unchanged).
     * @param  string  $type  inventory_ledger.type — 'sale' | 'stock_adjustment'.
     * @param  string|null  $saleType  'sale' | 'return' | 'defect' (null for adjustments).
     * @param  string  $referenceType  'transaction' | 'manual_adjustment' | 'return'.
     * @param  string|null  $referenceId  UUID of the source record, when any.
     *
     * @throws NegativeInventoryException when the movement would drive stock below zero.
     */
    public function record(
        Variant $variant,
        int $quantity,
        string $type,
        ?string $saleType,
        string $referenceType,
        ?string $referenceId,
        User $performedBy,
        ?string $notes = null,
    ): InventoryLedger {
        return DB::transaction(function () use (
            $variant, $quantity, $type, $saleType, $referenceType, $referenceId, $performedBy, $notes,
        ) {
            // Lock (or create) the snapshot row so concurrent writes serialize.
            $snapshot = StockSnapshot::query()
                ->lockForUpdate()
                ->firstOrCreate(['variant_id' => $variant->getKey()], ['quantity' => 0]);

            $resulting = $snapshot->quantity + $quantity;

            if ($resulting < 0) {
                throw new NegativeInventoryException;
            }

            $entry = InventoryLedger::create([
                'variant_id' => $variant->getKey(),
                'quantity' => $quantity,
                'resulting_snapshot' => $resulting,
                'type' => $type,
                'sale_type' => $saleType,
                'reference_id' => $referenceId,
                'reference_type' => $referenceType,
                'notes' => $notes,
                'performed_by' => $performedBy->getKey(),
            ]);

            $snapshot->update(['quantity' => $resulting]);

            return $entry;
        });
    }

    /**
     * Current on-hand for a variant, read from the snapshot cache (0 if none).
     */
    public function currentStock(Variant $variant): int
    {
        return (int) (StockSnapshot::where('variant_id', $variant->getKey())->value('quantity') ?? 0);
    }

    /**
     * Recompute on-hand directly from the ledger and rebuild the snapshot to
     * match. The ledger is the source of truth; this is the "reconciled against"
     * check from CLAUDE.md and a repair path after a restore.
     */
    public function reconcile(Variant $variant): int
    {
        return DB::transaction(function () use ($variant) {
            $total = (int) InventoryLedger::where('variant_id', $variant->getKey())->sum('quantity');

            StockSnapshot::updateOrCreate(
                ['variant_id' => $variant->getKey()],
                ['quantity' => $total],
            );

            return $total;
        });
    }
}
