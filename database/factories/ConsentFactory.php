<?php

namespace Database\Factories;

use App\Enums\ConsentStatus;
use App\Models\Consent;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consent>
 */
class ConsentFactory extends Factory
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
            'institution_id' => Institution::factory(),
            'scopes' => ['accounts', 'balances', 'transactions'],
            'status' => ConsentStatus::Active,
            'granted_at' => now(),
            'expires_at' => now()->addDays(90),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'granted_at' => now()->subDays(120),
            'expires_at' => now()->subDay(),
        ]);
    }
}
