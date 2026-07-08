<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variant>
 */
class VariantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'name' => fake()->randomElement(['Default', 'Small', 'Medium', 'Large']),
            'sku' => fake()->unique()->bothify('SKU-####??'),
            'barcode' => fake()->boolean(70) ? fake()->unique()->ean13() : null,
            'selling_price' => fake()->randomFloat(2, 1, 999),
            'cost' => fake()->randomFloat(2, 1, 500),
            'low_stock_threshold' => fake()->numberBetween(0, 10),
            'archived_at' => null,
        ];
    }

    /**
     * Indicate that the variant is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }

    /**
     * Indicate that the variant has no captured cost.
     */
    public function withoutCost(): static
    {
        return $this->state(fn (array $attributes) => [
            'cost' => null,
        ]);
    }

    /**
     * Indicate that the variant has no barcode (blank/unscannable).
     */
    public function withoutBarcode(): static
    {
        return $this->state(fn (array $attributes) => [
            'barcode' => null,
        ]);
    }
}
