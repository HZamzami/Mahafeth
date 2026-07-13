<?php

namespace Database\Factories;

use App\Models\InvestmentPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvestmentPlan>
 */
class InvestmentPlanFactory extends Factory
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
            'amount' => 100000,
            'monthly_contribution' => 2000,
            'weights' => ['AAPL' => 0.6, '2222.SR' => 0.4],
            'orders' => [
                ['symbol' => 'AAPL', 'name' => 'Apple Inc.', 'weight' => 0.6, 'value' => 60000.0, 'quantity' => 88.0],
                ['symbol' => '2222.SR', 'name' => 'Saudi Aramco', 'weight' => 0.4, 'value' => 40000.0, 'quantity' => 1360.0],
            ],
            'metrics' => [
                'expected_return' => 0.09,
                'volatility' => 0.14,
                'sharpe' => 0.34,
                'risk_alignment' => 93.0,
                'target_volatility' => 0.15,
                'shariah_applied' => false,
            ],
            'forecast' => [
                'months' => 120,
                'bands' => [
                    'p10' => [100000.0, 101000.0],
                    'p50' => [100000.0, 103000.0],
                    'p90' => [100000.0, 106000.0],
                ],
                'final' => ['p10' => 180000.0, 'p50' => 260000.0, 'p90' => 380000.0],
            ],
        ];
    }
}
