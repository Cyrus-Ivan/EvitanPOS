<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A sold line with an internally-coherent total (qty × price) and snapshotted
 * price/cost. It does NOT deduct stock or roll up into the header total —
 * invariant-sensitive tests should drive `pos.checkout` instead.
 *
 * @extends Factory<TransactionItem>
 */
class TransactionItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $price = fake()->randomFloat(2, 1, 999);

        return [
            'transaction_id' => Transaction::factory(),
            'variant_id' => Variant::factory(),
            'quantity' => $quantity,
            'selling_price' => $price,
            'cost' => fake()->randomFloat(2, 1, 500),
            'discount' => 0,
            'total' => round($quantity * $price, 2),
        ];
    }

    /**
     * Indicate that the line captured no cost (variant had none at sale time).
     */
    public function withoutCost(): static
    {
        return $this->state(fn (array $attributes) => [
            'cost' => null,
        ]);
    }
}
