<?php

namespace App\Services\Insights;

use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\GoalForecaster;

/**
 * Assembles the portfolio payload shared by every AI feature (one-shot
 * insights and the advisor chat), so both ground their answers in the
 * same data.
 */
class PortfolioContext
{
    public function __construct(private GoalForecaster $goalForecaster) {}

    /**
     * The JSON block describing the snapshot, profile, and goals.
     * Zakat is an obligation the Shariah card already reports with the
     * exact amount due; omitting it keeps the model from spending a
     * recommendation slot restating it.
     */
    public function payload(PortfolioSnapshot $snapshot, ?RiskProfile $riskProfile, array $goals = []): string
    {
        $metrics = $snapshot->metrics ?? [];

        $profile = $riskProfile === null ? null : [
            'risk_tolerance' => $riskProfile->risk_tolerance->value,
            'time_horizon' => $riskProfile->time_horizon->value,
            'target_return' => $riskProfile->target_return,
            'target_volatility' => $riskProfile->target_volatility,
            'liquidity_needs' => $riskProfile->liquidity_needs,
            'constraints' => $riskProfile->constraints,
        ];

        return json_encode([
            'as_of' => $snapshot->as_of->toDateString(),
            'total_value_sar' => $snapshot->total_value,
            'health_score' => $snapshot->health_score,
            'component_scores' => $snapshot->component_scores,
            'metrics' => array_diff_key($metrics, ['zakat' => null]),
            'investor_profile' => $profile,
            'goals' => $goals,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Goal forecasts for the AI prompt: probability of success at the
     * current allocation and at the optimal (tangency) mix.
     *
     * @return list<array{name: string, target_amount: float, months: int, monthly_contribution: ?float, probability: float, probability_optimal: ?float}>
     */
    public function goals(User $user, PortfolioSnapshot $snapshot): array
    {
        $metrics = $snapshot->metrics ?? [];

        if (! isset($metrics['expected_return'], $metrics['volatility'])) {
            return [];
        }

        $tangency = $metrics['frontier']['tangency'] ?? null;
        $payload = [];

        foreach ($user->goals()->orderBy('target_date')->get() as $goal) {
            $months = $goal->monthsRemaining();

            $current = $this->goalForecaster->forecast(
                currentValue: (float) $snapshot->total_value,
                annualReturn: (float) $metrics['expected_return'],
                annualVolatility: (float) $metrics['volatility'],
                targetAmount: $goal->target_amount,
                months: $months,
                monthlyContribution: $goal->monthly_contribution ?? 0.0,
            );

            $optimal = $tangency === null ? null : $this->goalForecaster->forecast(
                currentValue: (float) $snapshot->total_value,
                annualReturn: (float) $tangency['return'],
                annualVolatility: (float) $tangency['risk'],
                targetAmount: $goal->target_amount,
                months: $months,
                monthlyContribution: $goal->monthly_contribution ?? 0.0,
            );

            $payload[] = [
                'name' => $goal->name,
                'target_amount' => $goal->target_amount,
                'months' => $months,
                'monthly_contribution' => $goal->monthly_contribution,
                'probability' => $current['probability'],
                'probability_optimal' => $optimal['probability'] ?? null,
            ];
        }

        return $payload;
    }
}
