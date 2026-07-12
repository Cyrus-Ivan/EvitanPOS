<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => fake()->randomElement([
                'user.created', 'inventory.item.updated', 'settings.updated', 'pos.sale.created',
            ]),
            'auditable_type' => null,
            'auditable_id' => null,
            'description' => fake()->sentence(),
            'old_values' => null,
            'new_values' => null,
            'created_at' => fake()->dateTimeBetween('-1 month'),
        ];
    }

    /**
     * Force a specific action key.
     */
    public function action(string $action): static
    {
        return $this->state(fn (array $attributes) => ['action' => $action]);
    }
}
