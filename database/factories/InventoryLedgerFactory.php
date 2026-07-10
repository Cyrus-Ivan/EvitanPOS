<?php

namespace Database\Factories;

use App\Models\InventoryLedger;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryLedger>
 */
class InventoryLedgerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 50);

        return [
            'variant_id' => Variant::factory(),
            'quantity' => $quantity,
            'resulting_snapshot' => $quantity,
            'type' => 'stock_adjustment',
            'sale_type' => null,
            'reference_id' => null,
            'reference_type' => 'manual_adjustment',
            'notes' => fake()->boolean(50) ? fake()->sentence() : null,
            'performed_by' => User::factory(),
        ];
    }
}
