<?php

namespace Database\Factories;

use App\Enums\ObligationKind;
use App\Models\ObligationSettlement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ObligationSettlement>
 */
class ObligationSettlementFactory extends Factory
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
            'kind' => ObligationKind::Purification,
            'amount' => $this->faker->randomFloat(2, 10, 5000),
            'settled_through' => now()->toDateString(),
        ];
    }

    public function zakat(): static
    {
        return $this->state(fn (): array => ['kind' => ObligationKind::Zakat]);
    }
}
