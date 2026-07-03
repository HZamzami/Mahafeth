<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Connection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => Connection::factory(),
            'external_id' => fake()->unique()->uuid(),
            'name' => fake()->words(2, true).' Account',
            'type' => fake()->randomElement(AccountType::cases()),
            'currency' => 'USD',
        ];
    }
}
