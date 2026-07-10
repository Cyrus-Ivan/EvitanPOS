<?php

namespace Database\Factories;

use App\Models\ImportHistory;
use App\Models\User;
use App\Support\Inventory\InventoryCsvSchema;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportHistory>
 */
class ImportHistoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = fake()->numberBetween(1, 50);

        return [
            'user_id' => User::factory(),
            'filename' => fake()->slug().'.csv',
            'mode' => fake()->randomElement(InventoryCsvSchema::MODES),
            'status' => 'success',
            'total_rows' => $total,
            'success_count' => $total,
            'error_count' => 0,
            'errors' => null,
        ];
    }

    /**
     * A failed import carrying row-level errors.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'success_count' => 0,
            'error_count' => 1,
            'errors' => [
                ['row' => 2, 'messages' => ['SKU is required.']],
            ],
        ]);
    }
}
