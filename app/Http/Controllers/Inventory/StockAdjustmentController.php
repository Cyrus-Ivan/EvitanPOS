<?php

namespace App\Http\Controllers\Inventory;

use App\Exceptions\NegativeInventoryException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockAdjustmentRequest;
use App\Models\Variant;
use App\Support\Audit\AuditRecorder;
use App\Support\Inventory\StockLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StockAdjustmentController extends Controller
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Record a manual stock adjustment as a single ledger entry. Both input
     * modes resolve to a signed delta; a zero-delta no-op and any change that
     * would drive stock below zero are rejected as validation errors.
     */
    public function store(StockAdjustmentRequest $request, Variant $variant): RedirectResponse
    {
        $data = $request->validated();

        $delta = $data['mode'] === 'set'
            ? $data['value'] - $this->ledger->currentStock($variant)
            : $data['value'];

        if ($delta === 0) {
            throw ValidationException::withMessages([
                'value' => 'This adjustment would not change the stock level.',
            ]);
        }

        try {
            $this->ledger->record(
                variant: $variant,
                quantity: $delta,
                type: 'stock_adjustment',
                saleType: null,
                referenceType: 'manual_adjustment',
                referenceId: null,
                performedBy: $request->user(),
                notes: $data['notes'] ?? null,
            );
        } catch (NegativeInventoryException $e) {
            throw ValidationException::withMessages([
                'value' => $e->getMessage(),
            ]);
        }

        $this->audit->record(
            action: 'inventory.stock.adjusted',
            auditable: $variant,
            description: sprintf('Adjusted stock for variant by %+d.', $delta),
            new: [
                'delta' => $delta,
                'mode' => $data['mode'],
                'notes' => $data['notes'] ?? null,
            ],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Stock adjusted.',
        ]);
    }
}
