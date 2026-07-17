<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bare sale header for read-layer tests (sales history, reports, printing).
 *
 * The number here is unique but NOT gapless, and no items or ledger deductions
 * are written — anything exercising the commerce invariants (serial continuity,
 * stock, totals-from-lines) should drive `pos.checkout` instead.
 *
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = fake()->randomFloat(2, 20, 2000);
        // Cash tender rounded up to the next 100 so change stays realistic.
        $cashReceived = ceil($total / 100) * 100;

        return [
            'transaction_number' => str_pad((string) fake()->unique()->numberBetween(1, 9_999_999), 10, '0', STR_PAD_LEFT),
            'payment_type' => 'cash',
            'discount' => 0,
            'discount_type' => null,
            'total' => $total,
            'cash_received' => $cashReceived,
            'change' => round($cashReceived - $total, 2),
            'comment' => null,
            'user_id' => User::factory(),
        ];
    }

    /**
     * A cashless sale (no tender/change captured).
     */
    public function cashless(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_type' => 'cashless',
            'cash_received' => null,
            'change' => null,
        ]);
    }

    /**
     * An "others" payment (no tender/change captured).
     */
    public function others(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_type' => 'others',
            'cash_received' => null,
            'change' => null,
        ]);
    }
}
