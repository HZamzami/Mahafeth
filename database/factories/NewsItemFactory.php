<?php

namespace Database\Factories;

use App\Models\NewsItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsItem>
 */
class NewsItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $headline = fake()->sentence(8);

        return [
            'headline' => $headline,
            'headline_ar' => $headline,
            'source' => fake()->company(),
            'url' => fake()->url(),
            'minutes' => fake()->numberBetween(2, 10),
            'symbols' => [fake()->lexify('????')],
            'sectors' => null,
            'published_at' => fake()->dateTimeBetween('-7 days'),
        ];
    }
}
