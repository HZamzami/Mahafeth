<?php

namespace Database\Factories;

use App\Enums\RiskTolerance;
use App\Enums\TimeHorizon;
use App\Models\RiskProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RiskProfile>
 */
class RiskProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tolerance = fake()->randomElement(RiskTolerance::cases());

        return [
            'user_id' => User::factory(),
            'answers' => ['age' => 2, 'horizon' => 3, 'goal' => 3, 'drop_reaction' => 3, 'experience' => 3, 'liquidity' => 3, 'target_return' => 3, 'contributions' => 1, 'base_currency' => 1, 'shariah' => 1],
            'risk_tolerance' => $tolerance,
            'time_horizon' => fake()->randomElement(TimeHorizon::cases()),
            'target_return' => $tolerance->targetReturn(),
            'target_volatility' => $tolerance->targetVolatility(),
            'liquidity_needs' => 'moderate',
            'constraints' => null,
        ];
    }

    public function balanced(): static
    {
        return $this->state(fn (array $attributes) => [
            'risk_tolerance' => RiskTolerance::Balanced,
            'target_return' => RiskTolerance::Balanced->targetReturn(),
            'target_volatility' => RiskTolerance::Balanced->targetVolatility(),
        ]);
    }
}
