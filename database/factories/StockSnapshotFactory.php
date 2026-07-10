<?php

namespace Database\Factories;

use App\Models\StockSnapshot;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockSnapshot>
 */
class StockSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'variant_id' => Variant::factory(),
            'quantity' => fake()->numberBetween(0, 100),
        ];
    }
}
