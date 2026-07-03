<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\PriceHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceHistory>
 */
class PriceHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'date' => fake()->unique()->dateTimeBetween('-3 years')->format('Y-m-d'),
            'close' => fake()->randomFloat(4, 10, 500),
        ];
    }
}
