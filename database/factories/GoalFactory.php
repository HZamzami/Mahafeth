<?php

namespace Database\Factories;

use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
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
            'name' => fake()->randomElement(['Retirement', 'House Down Payment', 'Education Fund']),
            'target_amount' => fake()->randomFloat(2, 100_000, 3_000_000),
            'target_date' => fake()->dateTimeBetween('+2 years', '+20 years'),
            'monthly_contribution' => fake()->optional()->randomFloat(2, 500, 10_000),
        ];
    }
}
