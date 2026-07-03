<?php

namespace Database\Factories;

use App\Enums\AssetClass;
use App\Models\Asset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'symbol' => fake()->unique()->lexify('????'),
            'name' => fake()->company(),
            'name_ar' => null,
            'asset_class' => AssetClass::Equity,
            'sector' => fake()->randomElement(['Technology', 'Financials', 'Healthcare', 'Energy', 'Consumer']),
            'country' => fake()->randomElement(['US', 'SA', 'GB', 'JP']),
            'currency' => 'USD',
            'is_benchmark' => false,
        ];
    }

    public function benchmark(): static
    {
        return $this->state(fn (array $attributes) => [
            'asset_class' => AssetClass::Fund,
            'is_benchmark' => true,
        ]);
    }
}
