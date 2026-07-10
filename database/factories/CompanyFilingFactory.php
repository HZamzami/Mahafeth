<?php

namespace Database\Factories;

use App\Models\CompanyFiling;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyFiling>
 */
class CompanyFilingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $headline = fake()->sentence(6);

        return [
            'headline' => $headline,
            'headline_ar' => $headline,
            'symbol' => fake()->lexify('????'),
            'type' => fake()->randomElement(['quarterly_report', 'annual_report', 'announcement', 'dividend']),
            'source' => fake()->randomElement(['SEC', 'Tadawul']),
            'url' => fake()->url(),
            'excerpt' => fake()->paragraph(),
            'excerpt_ar' => fake()->paragraph(),
            'published_at' => fake()->dateTimeBetween('-30 days'),
        ];
    }
}
