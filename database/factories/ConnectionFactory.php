<?php

namespace Database\Factories;

use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Connection>
 */
class ConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'institution_id' => Institution::factory(),
            'status' => ConnectionStatus::Connected,
            'last_synced_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConnectionStatus::Pending,
            'last_synced_at' => null,
        ]);
    }
}
