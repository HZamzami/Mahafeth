<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Asset;
use App\Models\Holding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holding>
 */
class HoldingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'asset_id' => Asset::factory(),
            'quantity' => fake()->randomFloat(4, 1, 500),
            'avg_cost' => fake()->randomFloat(2, 10, 400),
        ];
    }
}
