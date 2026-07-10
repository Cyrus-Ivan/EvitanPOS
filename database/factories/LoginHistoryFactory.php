<?php

namespace Database\Factories;

use App\Enums\LoginEventType;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoginHistory>
 */
class LoginHistoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $occurredAt = fake()->dateTimeBetween('-1 month');

        return [
            'user_id' => User::factory(),
            'event_type' => fake()->randomElement(LoginEventType::cases()),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'notes' => null,
        ];
    }

    /**
     * Force a specific event type.
     */
    public function event(LoginEventType $type): static
    {
        return $this->state(fn (array $attributes) => ['event_type' => $type]);
    }
}
