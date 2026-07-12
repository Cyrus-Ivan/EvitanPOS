<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sales history (Phase 9). Read-only view over the append-only transactions /
 * transaction_items captured at checkout — no write logic lives here.
 *
 * Access is Cashier-level: every authenticated user may browse all sales (a
 * cashier must be able to look up any transaction to reprint or, later, return
 * it). Item cost is Owner-only, so the detail payload includes per-line cost
 * only for a viewer who may see it.
 */
class SalesController extends Controller
{
    /** Allowed payment types (mirrors the POS checkout). */
    private const PAYMENT_TYPES = ['cash', 'online', 'others'];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', '')) ?: null;
        $paymentType = $this->normalizePaymentType($request->query('payment_type'));
        $userFilter = $request->query('user') ?: null;
        $dateFrom = $this->normalizeDate($request->query('date_from'));
        $dateTo = $this->normalizeDate($request->query('date_to'));

        $transactions = Transaction::query()
            ->with('user:id,name,username')
            ->withCount('items')
            ->when($search, fn ($query) => $query->whereLikeAny(['transaction_number'], $search))
            ->when($paymentType, fn ($query) => $query->where('payment_type', $paymentType))
            ->when($userFilter, fn ($query) => $query->where('user_id', $userFilter))
            ->when($dateFrom, fn ($query) => $query->where('created_at', '>=', $dateFrom->startOfDay()))
            ->when($dateTo, fn ($query) => $query->where('created_at', '<=', $dateTo->endOfDay()))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'transaction_number' => $transaction->transaction_number,
                'payment_type' => $transaction->payment_type,
                'discount' => $transaction->discount,
                'discount_type' => $transaction->discount_type,
                'total' => $transaction->total,
                'created_at' => $transaction->created_at,
                'items_count' => $transaction->items_count,
                'user' => $transaction->user ? [
                    'id' => $transaction->user->id,
                    'name' => $transaction->user->name,
                    'username' => $transaction->user->username,
                ] : null,
            ]);

        return Inertia::render('sales', [
            'transactions' => $transactions,
            // Archived cashiers still appear so their historical sales stay filterable.
            'users' => User::withTrashed()->orderBy('name')->get(['id', 'name', 'username']),
            'paymentTypes' => self::PAYMENT_TYPES,
            'filters' => [
                'search' => $search,
                'payment_type' => $paymentType,
                'user' => $userFilter,
                'date_from' => $dateFrom?->toDateString(),
                'date_to' => $dateTo?->toDateString(),
            ],
        ]);
    }

    public function show(Transaction $transaction): Response
    {
        $transaction->load(['items.variant.item:id,name,unit', 'user:id,name,username']);

        // Item cost is Owner-only; gate it here because we hand-build the payload
        // (bypassing the model's HidesCostFromNonOwners serialization guard).
        $canViewCost = Gate::allows('view-item-cost');

        return Inertia::render('sales/show', [
            'transaction' => [
                'id' => $transaction->id,
                'transaction_number' => $transaction->transaction_number,
                'payment_type' => $transaction->payment_type,
                'discount' => $transaction->discount,
                'discount_type' => $transaction->discount_type,
                'total' => $transaction->total,
                'cash_received' => $transaction->cash_received,
                'change' => $transaction->change,
                'comment' => $transaction->comment,
                'created_at' => $transaction->created_at,
                'user' => $transaction->user ? [
                    'id' => $transaction->user->id,
                    'name' => $transaction->user->name,
                    'username' => $transaction->user->username,
                ] : null,
                'items' => $transaction->items->map(function (TransactionItem $item) use ($canViewCost) {
                    $line = [
                        'id' => $item->id,
                        'label' => $this->itemLabel($item),
                        'quantity' => $item->quantity,
                        'selling_price' => $item->selling_price,
                        'discount' => $item->discount,
                        'total' => $item->total,
                    ];

                    // Cost is Owner-only: the key is absent entirely for others.
                    if ($canViewCost) {
                        $line['cost'] = $item->cost;
                    }

                    return $line;
                })->all(),
            ],
            'canViewCost' => $canViewCost,
        ]);
    }

    /**
     * The display label for a sold line: item name, plus the variant name when
     * the item has named variants (a single-product item keeps an unnamed
     * variant and falls back to the item name).
     */
    private function itemLabel(TransactionItem $item): string
    {
        $name = $item->variant?->item?->name ?? 'Unknown product';
        $variantName = $item->variant?->name;

        return trim($name.($variantName ? ' — '.$variantName : ''));
    }

    /**
     * Constrain the payment-type filter to a known value, else null.
     */
    private function normalizePaymentType(mixed $value): ?string
    {
        $value = (string) $value;

        return in_array($value, self::PAYMENT_TYPES, true) ? $value : null;
    }

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
