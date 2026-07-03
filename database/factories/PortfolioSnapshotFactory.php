<?php

namespace Database\Factories;

use App\Models\PortfolioSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortfolioSnapshot>
 */
class PortfolioSnapshotFactory extends Factory
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
            'as_of' => fake()->unique()->dateTimeBetween('-1 year')->format('Y-m-d'),
            'total_value' => fake()->randomFloat(2, 100_000, 2_000_000),
            'health_score' => fake()->numberBetween(40, 95),
            'component_scores' => null,
            'metrics' => null,
        ];
    }
}
