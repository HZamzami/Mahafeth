<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiInsight;
use App\Models\Goal;
use App\Models\PortfolioSnapshot;
use App\Models\User;
use App\Services\Analytics\GoalForecaster;
use App\Services\Analytics\HoldingsSummarizer;
use App\Services\Analytics\PortfolioDataAssembler;
use App\Services\Analytics\RealizedGainCalculator;
use App\Services\Analytics\RebalancePlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function show(Request $request, HoldingsSummarizer $summarizer, RealizedGainCalculator $realizedGainCalculator): JsonResponse
    {
        $user = $request->user();
        $snapshot = $user->latestSnapshot();

        return response()->json([
            'data' => [
                'has_snapshot' => $snapshot !== null,
                'as_of' => $snapshot?->as_of->toDateString(),
                'total_value' => $snapshot?->total_value,
                'health_score' => $snapshot?->health_score,
                'component_scores' => $snapshot?->component_scores,
                'goal_forecasts' => $this->goalForecasts($user, $snapshot),
                'rebalance_orders' => $this->rebalanceOrders($user, $snapshot),
                'holdings' => $summarizer->rows($user),
                'realized_pl' => $realizedGainCalculator->forUser($user),
                'insight' => $snapshot === null ? null : $this->insight($snapshot),
            ],
        ]);
    }

    /**
     * @return array{summary: string, recommendations: array<int, mixed>}|null
     */
    private function insight(PortfolioSnapshot $snapshot): ?array
    {
        $insight = AiInsight::query()
            ->where('portfolio_snapshot_id', $snapshot->id)
            ->where('locale', app()->getLocale())
            ->first();

        if ($insight === null) {
            return null;
        }

        return [
            'summary' => $insight->summary,
            'recommendations' => $insight->recommendations,
        ];
    }

    /**
     * @return list<array{symbol: string, name: string, side: string, quantity: float, value: float, current_weight: float, target_weight: float}>
     */
    private function rebalanceOrders(User $user, ?PortfolioSnapshot $snapshot): array
    {
        $metrics = $snapshot?->metrics ?? [];
        $targetWeights = $metrics['frontier']['recommended']['weights'] ?? $metrics['frontier']['tangency']['weights'] ?? [];

        if ($snapshot === null || $targetWeights === [] || ! isset($metrics['weights'])) {
            return [];
        }

        $windowYears = $user->riskProfile?->time_horizon->analysisWindowYears()
            ?? (int) config('mahafeth.analysis_window_years');
        $data = app(PortfolioDataAssembler::class)->forUser($user, now()->subYears($windowYears));

        return app(RebalancePlanner::class)->plan(
            currentWeights: $metrics['weights'],
            targetWeights: $targetWeights,
            totalValue: (float) $snapshot->total_value,
            quantities: $data['quantities'],
            assets: $data['assets'],
            shariahRequired: (bool) ($user->riskProfile?->constraints['shariah_required'] ?? false),
        );
    }

    /**
     * @return list<array{name: string, target: float, months: int, probability: float, probabilityOptimal: ?float, median: float}>
     */
    private function goalForecasts(User $user, ?PortfolioSnapshot $snapshot): array
    {
        $metrics = $snapshot?->metrics ?? [];

        if ($snapshot === null || ! isset($metrics['expected_return'], $metrics['volatility'])) {
            return [];
        }

        $forecaster = app(GoalForecaster::class);
        $tangency = $metrics['frontier']['tangency'] ?? null;
        $rows = [];

        foreach ($user->goals()->orderBy('target_date')->get() as $goal) {
            /** @var Goal $goal */
            $months = $goal->monthsRemaining();

            $current = $forecaster->forecast(
                currentValue: (float) $snapshot->total_value,
                annualReturn: (float) $metrics['expected_return'],
                annualVolatility: (float) $metrics['volatility'],
                targetAmount: $goal->target_amount,
                months: $months,
                monthlyContribution: $goal->monthly_contribution ?? 0.0,
            );

            $optimal = $tangency === null ? null : $forecaster->forecast(
                currentValue: (float) $snapshot->total_value,
                annualReturn: (float) $tangency['return'],
                annualVolatility: (float) $tangency['risk'],
                targetAmount: $goal->target_amount,
                months: $months,
                monthlyContribution: $goal->monthly_contribution ?? 0.0,
            );

            $rows[] = [
                'name' => $goal->name,
                'target' => $goal->target_amount,
                'months' => $months,
                'probability' => $current['probability'],
                'probabilityOptimal' => $optimal['probability'] ?? null,
                'median' => $current['final']['p50'],
            ];
        }

        return $rows;
    }
}
