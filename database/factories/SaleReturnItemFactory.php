<?php

namespace Database\Factories;

use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\TransactionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An after-sales line. Variant and price/cost snapshots are derived from the
 * source sold line (pass `transaction_item_id` to target a real one), matching
 * the "always the captured price, never the current variant price" rule. No
 * ledger entry is written — stock-affecting tests should use ProcessSaleReturn.
 *
 * @extends Factory<SaleReturnItem>
 */
class SaleReturnItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_return_id' => SaleReturn::factory(),
            // Listed before the closures below so it is already resolved to a key
            // when they run.
            'transaction_item_id' => TransactionItem::factory(),
            'variant_id' => fn (array $attributes) => $this->sourceItem($attributes)->variant_id,
            'quantity' => 1,
            'selling_price' => fn (array $attributes) => $this->sourceItem($attributes)->selling_price,
            'cost' => fn (array $attributes) => $this->sourceItem($attributes)->cost,
            'refund_amount' => fn (array $attributes) => $this->sourceItem($attributes)->selling_price,
        ];
    }

    /**
     * The sold line this after-sales line reverses.
     */
    private function sourceItem(array $attributes): TransactionItem
    {
        return TransactionItem::query()->findOrFail($attributes['transaction_item_id']);
    }
}
