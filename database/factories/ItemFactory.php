<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'unit' => fake()->randomElement(['pc', 'kg', 'box', 'pack']),
            'category_id' => null,
            'description' => fake()->optional()->sentence(),
            'archived_at' => null,
        ];
    }

    /**
     * Indicate that the item is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }

    /**
     * Give the item at least one active variant, satisfying the
     * "every item must have >= 1 variant" invariant for tests.
     */
    public function withVariant(): static
    {
        return $this->afterCreating(function (Item $item): void {
            if ($item->variants()->count() === 0) {
                $item->variants()->save(Variant::factory()->make());
            }
        });
    }
}
