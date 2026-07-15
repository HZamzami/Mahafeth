<?php

namespace App\Services\Analytics;

use App\Models\Asset;
use App\Models\User;

/**
 * Pre-trade decision support: recomputes the portfolio's diversification,
 * risk, and health score as if a hypothetical buy or sell had already
 * happened, and reports the before/after deltas.
 *
 * History-driven metrics that a single trade cannot rewrite (max drawdown,
 * beta) are carried over unchanged from the latest snapshot when scoring
 * health.
 */
class WhatIfSimulator
{
    private const METRIC_KEYS = [
        'volatility', 'sharpe', 'expected_return', 'hhi', 'effective_holdings',
        'largest_weight', 'average_correlation', 'compliant_weight',
    ];

    public function __construct(
        private PortfolioDataAssembler $assembler,
        private ReturnCalculator $returnCalculator,
        private CovarianceMatrixService $covarianceMatrixService,
        private CorrelationAnalyzer $correlationAnalyzer,
        private RiskAnalyzer $riskAnalyzer,
        private DiversificationAnalyzer $diversificationAnalyzer,
        private ShariahComplianceAnalyzer $shariahComplianceAnalyzer,
        private HealthScoreCalculator $healthScoreCalculator,
    ) {}

    /**
     * @return array{
     *     before: array<string, ?float>,
     *     after: array<string, ?float>,
     *     deltas: array<string, ?float>,
     *     health_before: ?int,
     *     health_after: ?int,
     *     quantity: float
     * }|null null when the portfolio or the instrument lacks price history
     */
    public function simulate(User $user, string $symbol, float $amount, bool $sell = false): ?array
    {
        $symbol = strtoupper($symbol);
        $riskProfile = $user->riskProfile()->first();
        $windowYears = $riskProfile?->time_horizon->analysisWindowYears()
            ?? (int) config('mahafeth.analysis_window_years');
        $from = now()->subYears($windowYears);

        $data = $this->assembler->forUser($user, $from);

        if ($data['priceSeries'] === []) {
            return null;
        }

        if (! isset($data['priceSeries'][$symbol])) {
            if ($sell) {
                return null;
            }

            $asset = Asset::where('symbol', $symbol)->first();
            $series = $this->assembler->seriesFor([$symbol], $from)[$symbol] ?? [];

            if ($asset === null || count($series) < 2) {
                return null;
            }

            $data['priceSeries'][$symbol] = $series;
            $data['quantities'][$symbol] = 0.0;
            $data['assets'][$symbol] = [
                'name' => $asset->localizedName(),
                'asset_class' => $asset->asset_class->value,
                'sector' => $asset->sector,
                'country' => $asset->country,
                'currency' => $asset->currency,
                'shariah_status' => $asset->shariah_status->value,
                'purification_rate' => $asset->purification_rate,
            ];
        }

        $lastClose = end($data['priceSeries'][$symbol]);

        if ($lastClose <= 0) {
            return null;
        }

        $held = $data['quantities'][$symbol] ?? 0.0;
        $quantity = $sell
            ? -min($held, $amount / $lastClose)
            : $amount / $lastClose;

        if ($quantity === 0.0) {
            return null;
        }

        $before = $this->metrics($data, $data['quantities']);

        $adjusted = $data['quantities'];
        $adjusted[$symbol] = max(0.0, ($adjusted[$symbol] ?? 0.0) + $quantity);
        $after = $this->metrics($data, $adjusted);

        if ($before === null || $after === null) {
            return null;
        }

        $deltas = [];
        foreach (self::METRIC_KEYS as $key) {
            $deltas[$key] = ($before[$key] !== null && $after[$key] !== null)
                ? round($after[$key] - $before[$key], 6)
                : null;
        }

        [$healthBefore, $healthAfter] = $this->healthScores($user, $riskProfile, $before, $after);

        return [
            'before' => $before,
            'after' => $after,
            'deltas' => $deltas,
            'health_before' => $healthBefore,
            'health_after' => $healthAfter,
            'quantity' => round($quantity, 6),
        ];
    }

    /**
     * Portfolio metrics for a given set of quantities over shared data.
     *
     * @param  array{priceSeries: array<string, array<string, float>>, assets: array<string, array<string, mixed>>, dividends: array<string, float>}  $data
     * @param  array<string, float>  $quantities
     * @return array<string, ?float>|null
     */
    private function metrics(array $data, array $quantities): ?array
    {
        $quantities = array_filter($quantities, fn (float $quantity): bool => $quantity > 1e-9);

        if ($quantities === []) {
            return null;
        }

        $priceSeries = array_intersect_key($data['priceSeries'], $quantities);
        $values = $this->returnCalculator->portfolioValueSeries($priceSeries, $quantities);

        if (count($values) < 2) {
            return null;
        }

        $totalValue = end($values);
        $weights = [];

        foreach ($priceSeries as $seriesSymbol => $series) {
            $weights[$seriesSymbol] = $totalValue > 0 ? ($quantities[$seriesSymbol] * end($series)) / $totalValue : 0.0;
        }

        $aligned = $this->returnCalculator->alignedLogReturns($priceSeries);
        $covariance = $this->covarianceMatrixService->matrix($aligned);
        $correlation = $this->correlationAnalyzer->matrix($covariance);

        $portfolioReturns = array_values($this->returnCalculator->logReturns($values));
        $annualReturn = $this->returnCalculator->annualizedReturn($portfolioReturns);
        $volatility = $this->riskAnalyzer->portfolioVolatility($weights, $covariance);

        $shariah = $this->shariahComplianceAnalyzer->analyze($weights, $data['assets'], $data['dividends']);

        return [
            'volatility' => $volatility,
            'sharpe' => $this->riskAnalyzer->sharpeRatio($annualReturn, $volatility),
            'expected_return' => $annualReturn,
            'hhi' => $this->diversificationAnalyzer->hhi($weights),
            'effective_holdings' => $this->diversificationAnalyzer->effectiveHoldings($weights),
            'largest_weight' => $this->diversificationAnalyzer->largestPosition($weights),
            'average_correlation' => count($weights) > 1 ? $this->correlationAnalyzer->averageCorrelation($correlation) : null,
            'compliant_weight' => $shariah['compliant_weight'],
        ];
    }

    /**
     * Health scores for both states by overlaying the recomputed metrics
     * on the latest snapshot's payload.
     *
     * @param  array<string, ?float>  $before
     * @param  array<string, ?float>  $after
     * @return array{0: ?int, 1: ?int}
     */
    private function healthScores(User $user, ?object $riskProfile, array $before, array $after): array
    {
        $snapshotMetrics = $user->latestSnapshot()?->metrics;

        if ($riskProfile === null || $snapshotMetrics === null) {
            return [null, null];
        }

        $score = function (array $state) use ($snapshotMetrics, $riskProfile): int {
            $metrics = array_merge($snapshotMetrics, [
                'volatility' => $state['volatility'],
                'sharpe' => $state['sharpe'],
                'expected_return' => $state['expected_return'],
                'hhi' => $state['hhi'],
                'effective_holdings' => $state['effective_holdings'],
                'average_correlation' => $state['average_correlation'] ?? ($snapshotMetrics['average_correlation'] ?? 0.0),
                'largest_position' => ['weight' => $state['largest_weight']] + ($snapshotMetrics['largest_position'] ?? []),
                'shariah' => ['compliant_weight' => $state['compliant_weight']] + ($snapshotMetrics['shariah'] ?? []),
            ]);

            return $this->healthScoreCalculator->calculate(
                $metrics,
                $riskProfile->target_volatility,
                $riskProfile->target_return,
                (bool) ($riskProfile->constraints['shariah_required'] ?? false),
            )['overall'];
        };

        return [$score($before), $score($after)];
    }
}
