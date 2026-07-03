<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(4, 1, 100);
        $price = fake()->randomFloat(2, 10, 400);

        return [
            'account_id' => Account::factory(),
            'asset_id' => Asset::factory(),
            'type' => fake()->randomElement([TransactionType::Buy, TransactionType::Sell]),
            'quantity' => $quantity,
            'price' => $price,
            'amount' => round($quantity * $price, 4),
            'executed_at' => fake()->dateTimeBetween('-2 years'),
        ];
    }
}
