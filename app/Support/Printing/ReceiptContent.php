<?php

namespace App\Support\Printing;

use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Support\Settings\BusinessSettings;

/**
 * Builds the plain data array a receipt is rendered from — the single source of
 * truth for what a receipt says, consumed by {@see EscPosReceiptBuilder}.
 *
 * Reads business identity + footer server-side (they are never shared to the
 * client) and formats money from the transaction's decimal-string values. It
 * deliberately never includes item cost: receipts are customer-facing.
 */
class ReceiptContent
{
    /** Human labels for the stored payment types (mirrors the POS/sales views). */
    private const PAYMENT_LABELS = [
        'cash' => 'Cash',
        'cashless' => 'Cashless',
        'others' => 'Others',
    ];

    public function __construct(
        private readonly BusinessSettings $business,
        private readonly PrinterSettings $printer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forTransaction(Transaction $transaction, bool $isReprint = false): array
    {
        $transaction->loadMissing(['items.variant.item:id,name,unit', 'user:id,name,username']);

        $business = $this->business->all();

        // Subtotal is not stored; derive it from the line totals in centavos to
        // avoid float drift, then the discount actually taken is subtotal - total.
        $subtotal = 0;
        $items = $transaction->items->map(function (TransactionItem $item) use (&$subtotal): array {
            $subtotal += $this->centavos($item->total);

            return [
                'label' => $item->displayLabel(' - '),
                'quantity' => (int) $item->quantity,
                'unit_price' => (string) $item->selling_price,
                'line_total' => (string) $item->total,
            ];
        })->all();

        $total = $this->centavos($transaction->total);
        $discountAmount = max(0, $subtotal - $total);

        return [
            'paper_size' => $this->printer->paperSize(),
            'is_reprint' => $isReprint,
            'business' => [
                'name' => $business['business_name'] ?? '',
                'address' => $business['business_address'] ?? '',
                'tin' => $business['tin'] ?? '',
            ],
            'footer' => $business['receipt_footer'] ?? '',
            'currency_symbol' => $business['currency_symbol'] ?? '₱',
            'number' => (string) $transaction->transaction_number,
            'datetime' => $transaction->created_at
                ?->timezone(config('app.timezone'))
                ->format('Y-m-d h:i A') ?? '',
            'cashier' => $transaction->user?->name ?? $transaction->user?->username ?? '-',
            'payment_label' => self::PAYMENT_LABELS[$transaction->payment_type] ?? $transaction->payment_type,
            'comment' => $transaction->comment,
            'items' => $items,
            'subtotal' => $this->pesos($subtotal),
            'discount_display' => $this->discountDisplay($transaction),
            'discount_amount' => $this->pesos($discountAmount),
            'total' => (string) $transaction->total,
            'cash_received' => $transaction->payment_type === 'cash' ? (string) $transaction->cash_received : null,
            'change' => $transaction->payment_type === 'cash' ? (string) $transaction->change : null,
        ];
    }

    /**
     * A short label describing the discount, or null when none applied. For a
     * percentage this is e.g. "10%"; for an amount there is nothing to add
     * beyond the value already shown on the discount line.
     */
    private function discountDisplay(Transaction $transaction): ?string
    {
        if ($transaction->discount_type !== 'percentage' || (float) $transaction->discount <= 0) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $transaction->discount, 2, '.', ''), '0'), '.').'%';
    }

    private function centavos(string|float|int|null $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function pesos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
