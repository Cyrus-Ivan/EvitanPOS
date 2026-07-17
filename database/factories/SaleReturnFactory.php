<?php

namespace Database\Factories;

use App\Models\SaleReturn;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bare after-sales header for read-layer tests. The number matches the
 * RET-YYYYMMDD-###### shape but is unique-random, not the daily sequence, and
 * no lines or ledger entries are written — anything exercising quota, refund
 * math or stock restoration should go through ProcessSaleReturn instead.
 *
 * @extends Factory<SaleReturn>
 */
class SaleReturnFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'return_number' => sprintf('RET-%s-%06d', now()->format('Ymd'), fake()->unique()->numberBetween(1, 999_999)),
            'transaction_id' => Transaction::factory(),
            'type' => 'return',
            'reason' => fake()->optional()->sentence(),
            'refund_payment_type' => 'cash',
            'total_refund' => fake()->randomFloat(2, 1, 500),
            'performed_by' => User::factory(),
        ];
    }

    /**
     * A defect event: stock is not restored and no money changes hands at the
     * counter, so there is no refund payment type.
     */
    public function defect(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'defect',
            'refund_payment_type' => null,
        ]);
    }
}
