<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Sales\SaleReturnRequest;
use App\Models\Transaction;
use App\Support\Sales\ProcessSaleReturn;
use Illuminate\Http\RedirectResponse;

/**
 * After-sales workflow (Phase 10): returns and defects against a completed sale.
 * Writes to the separate sale_returns family — the original transaction and its
 * items are never mutated. All the money/quota/ledger logic lives in
 * ProcessSaleReturn; this controller only enforces the per-type role floor and
 * translates the result into a toast.
 *
 * Access: returns are Cashier-level (the route `auth` group). Defects (inventory
 * write-off) are higher-risk and require Admin+.
 */
class SaleReturnController extends Controller
{
    public function __construct(
        private readonly ProcessSaleReturn $processor,
    ) {}

    public function store(SaleReturnRequest $request, Transaction $transaction): RedirectResponse
    {
        $type = $request->validated('type');

        // Defects are Admin+; returns are available to any cashier.
        if ($type === 'defect'
            && ! $request->user()->hasRoleAtLeast(UserRole::Admin)) {
            abort(403);
        }

        $saleReturn = $this->processor->process(
            transaction: $transaction,
            type: $type,
            lines: $request->validated('lines', []),
            reason: $request->validated('reason'),
            refundPaymentType: $request->validated('refund_payment_type'),
            performedBy: $request->user(),
        );

        $label = match ($type) {
            'defect' => 'Defect',
            default => 'Return',
        };

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$label} {$saleReturn->return_number} recorded.",
        ]);
    }
}
