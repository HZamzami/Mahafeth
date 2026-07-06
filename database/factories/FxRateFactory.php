<?php

namespace Database\Factories;

use App\Models\FxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FxRate>
 */
class FxRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'currency' => fake()->unique()->currencyCode(),
            'rate' => fake()->randomFloat(6, 0.1, 10),
            'fetched_at' => now(),
        ];
    }
}
