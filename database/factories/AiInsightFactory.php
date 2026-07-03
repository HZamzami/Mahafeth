<?php

namespace Database\Factories;

use App\Models\AiInsight;
use App\Models\PortfolioSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiInsight>
 */
class AiInsightFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'portfolio_snapshot_id' => PortfolioSnapshot::factory(),
            'locale' => 'en',
            'summary' => fake()->paragraph(),
            'recommendations' => [
                ['title' => fake()->sentence(4), 'body' => fake()->sentence(12), 'priority' => 'high'],
                ['title' => fake()->sentence(4), 'body' => fake()->sentence(12), 'priority' => 'medium'],
            ],
        ];
    }
}
