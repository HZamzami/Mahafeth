<?php

namespace App\Actions;

use App\Contracts\InsightGenerator;
use App\Models\AiInsight;
use App\Models\PortfolioSnapshot;
use App\Models\User;
use App\Services\Analytics\GoalForecaster;

/**
 * Generates (or regenerates) the AI explanation for a user's latest
 * portfolio snapshot in the given locale, and persists it.
 */
class GenerateInsights
{
    public function __construct(
        private InsightGenerator $generator,
        private GoalForecaster $goalForecaster,
    ) {}

    public function handle(User $user, string $locale): ?AiInsight
    {
        $snapshot = $user->latestSnapshot();

        if ($snapshot === null) {
            return null;
        }

        $result = $this->generator->generate(
            $snapshot,
            $user->riskProfile,
            $locale,
            $this->goalPayload($user, $snapshot),
        );

        return AiInsight::updateOrCreate(
            ['portfolio_snapshot_id' => $snapshot->id, 'locale' => $locale],
            ['summary' => $result['summary'], 'recommendations' => $result['recommendations']],
        );
    }

    /**
     * Goal forecasts for the AI prompt: probability of success at the
     * current allocation and at the optimal (tangency) mix.
     *
     * @return list<array{name: string, target_amount: float, months: int, monthly_contribution: ?float, probability: float, probability_optimal: ?float}>
     */
    private function goalPayload(User $user, PortfolioSnapshot $snapshot): array
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
