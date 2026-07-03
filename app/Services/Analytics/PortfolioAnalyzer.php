<?php

namespace App\Services\Analytics;

use App\Models\PortfolioSnapshot;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Runs the full analytics pipeline for a user's unified portfolio and stores
 * the result as a portfolio snapshot: returns → covariance/correlation →
 * risk + diversification metrics → snapshot row.
 *
 * Health scoring (component scores + overall score) is layered on top of the
 * stored metrics separately.
 */
class PortfolioAnalyzer
{
    public function __construct(
        private PortfolioDataAssembler $assembler,
        private ReturnCalculator $returnCalculator,
        private CovarianceMatrixService $covarianceMatrixService,
        private CorrelationAnalyzer $correlationAnalyzer,
        private RiskAnalyzer $riskAnalyzer,
        private DiversificationAnalyzer $diversificationAnalyzer,
        private HealthScoreCalculator $healthScoreCalculator,
        private EfficientFrontierService $efficientFrontierService,
        private RiskDecomposer $riskDecomposer,
    ) {}

    /**
     * Analyze the user's portfolio as of today. Returns null when there is
     * nothing to analyze (no connected holdings with price history).
     */
    public function analyze(User $user): ?PortfolioSnapshot
    {
        // The IPS drives the lookback: longer horizons analyze more history.
        $riskProfile = $user->riskProfile()->first();
        $windowYears = $riskProfile?->time_horizon->analysisWindowYears()
            ?? (int) config('mahafeth.analysis_window_years');

        $from = now()->subYears($windowYears);
        $data = $this->assembler->forUser($user, $from);

        if ($data['priceSeries'] === []) {
            return null;
        }

        $values = $this->returnCalculator->portfolioValueSeries($data['priceSeries'], $data['quantities']);

        if (count($values) < 2) {
            return null;
        }

        $totalValue = end($values);
        $weights = $this->currentWeights($data['priceSeries'], $data['quantities'], $totalValue);

        $aligned = $this->returnCalculator->alignedLogReturns($data['priceSeries']);
        $covariance = $this->covarianceMatrixService->matrix($aligned);
        $correlation = $this->correlationAnalyzer->matrix($covariance);
        $averageCorrelation = $this->correlationAnalyzer->averageCorrelation($correlation);

        $portfolioReturns = $this->returnCalculator->logReturns($values);
        $annualReturn = $this->returnCalculator->annualizedReturn(array_values($portfolioReturns));
        $volatility = $this->riskAnalyzer->portfolioVolatility($weights, $covariance);
        $downsideDeviation = $this->riskAnalyzer->downsideDeviation(array_values($portfolioReturns));

        $assetVolatilities = [];
        foreach ($covariance as $symbol => $row) {
            $assetVolatilities[$symbol] = sqrt(max(0.0, $row[$symbol]));
        }

        $largestSymbol = array_search(max($weights), $weights, true);

        $benchmark = $this->benchmarkStats($portfolioReturns, $from);

        $assetExpectedReturns = array_map(
            fn (array $returns): float => $this->returnCalculator->annualizedReturn($returns),
            $aligned,
        );

        $frontier = $this->efficientFrontierService->analyze($assetExpectedReturns, $covariance, $weights, samples: 4000);

        $sectors = array_filter(array_map(fn (array $asset) => $asset['sector'], $data['assets']));
        $countries = array_filter(array_map(fn (array $asset) => $asset['country'], $data['assets']));

        $metrics = [
            'window_years' => $windowYears,
            'expected_return' => $annualReturn,
            'volatility' => $volatility,
            'beta' => $benchmark['beta'],
            'sharpe' => $this->riskAnalyzer->sharpeRatio($annualReturn, $volatility),
            'sortino' => $this->riskAnalyzer->sortinoRatio($annualReturn, $downsideDeviation),
            'var_95' => $this->riskAnalyzer->valueAtRisk($annualReturn, $volatility),
            'cvar_95' => $this->riskAnalyzer->conditionalValueAtRisk($annualReturn, $volatility),
            'max_drawdown' => $this->riskAnalyzer->maxDrawdown($values),
            'hhi' => $this->diversificationAnalyzer->hhi($weights),
            'effective_holdings' => $this->diversificationAnalyzer->effectiveHoldings($weights),
            'diversification_ratio' => $this->diversificationAnalyzer->diversificationRatio($weights, $assetVolatilities, $volatility),
            'largest_position' => [
                'symbol' => $largestSymbol,
                'name' => $data['assets'][$largestSymbol]['name'] ?? $largestSymbol,
                'weight' => $this->diversificationAnalyzer->largestPosition($weights),
            ],
            'average_correlation' => $averageCorrelation,
            'stress_correlation' => $this->correlationAnalyzer->stressCorrelation($averageCorrelation),
            'pca_first_factor_share' => $this->correlationAnalyzer->firstFactorShare($covariance),
            'weights' => $weights,
            'allocations' => [
                'asset_class' => $this->diversificationAnalyzer->groupWeights($weights, array_map(fn (array $asset) => $asset['asset_class'], $data['assets'])),
                'sector' => $this->diversificationAnalyzer->groupWeights($weights, $sectors),
                'country' => $this->diversificationAnalyzer->groupWeights($weights, $countries),
                'currency' => $this->diversificationAnalyzer->groupWeights($weights, array_map(fn (array $asset) => $asset['currency'], $data['assets'])),
            ],
            'frontier' => [
                'points' => $frontier['frontier'],
                'tangency' => $frontier['tangency'],
                'current' => $frontier['current'],
                'efficiency_gap' => $frontier['efficiency_gap'],
            ],
            'risk_decomposition' => $this->riskDecomposer->systematicSplit(
                $benchmark['beta'],
                $benchmark['variance'],
                $volatility ** 2,
            ) + [
                'sector_contributions' => $this->riskDecomposer->contributions($weights, $covariance, $sectors),
                'country_contributions' => $this->riskDecomposer->contributions($weights, $covariance, $countries),
            ],
        ];

        $attributes = ['total_value' => $totalValue, 'metrics' => $metrics];

        // Health scoring requires the investor's IPS targets; without a
        // risk profile the gauge stays locked.
        if ($riskProfile !== null) {
            $health = $this->healthScoreCalculator->calculate(
                $metrics,
                $riskProfile->target_volatility,
                $riskProfile->target_return,
            );

            $attributes['component_scores'] = $health['components'];
            $attributes['health_score'] = $health['overall'];
        }

        return $user->portfolioSnapshots()->updateOrCreate(
            ['as_of' => today()->toDateString()],
            $attributes,
        );
    }

    /**
     * Current portfolio weights from the latest close of each series.
     *
     * @param  array<string, array<string, float>>  $priceSeries
     * @param  array<string, float>  $quantities
     * @return array<string, float> symbol => weight
     */
    private function currentWeights(array $priceSeries, array $quantities, float $totalValue): array
    {
        $weights = [];

        foreach ($priceSeries as $symbol => $series) {
            $lastClose = end($series);
            $weights[$symbol] = $totalValue > 0 ? (($quantities[$symbol] ?? 0.0) * $lastClose) / $totalValue : 0.0;
        }

        return $weights;
    }

    /**
     * Portfolio beta against the configured benchmark (aligned by date) and
     * the benchmark's annualized variance.
     *
     * @param  array<string, float>  $portfolioReturns  date => log return
     * @return array{beta: float, variance: float}
     */
    private function benchmarkStats(array $portfolioReturns, CarbonInterface $from): array
    {
        $benchmarkPrices = $this->assembler->benchmarkSeries($from);

        if ($benchmarkPrices === []) {
            return ['beta' => 0.0, 'variance' => 0.0];
        }

        $benchmarkReturns = $this->returnCalculator->logReturns($benchmarkPrices);
        $commonDates = array_intersect_key($portfolioReturns, $benchmarkReturns);

        $portfolio = [];
        $benchmark = [];

        foreach (array_keys($commonDates) as $date) {
            $portfolio[] = $portfolioReturns[$date];
            $benchmark[] = $benchmarkReturns[$date];
        }

        return [
            'beta' => $this->riskAnalyzer->beta($portfolio, $benchmark),
            'variance' => $this->covarianceMatrixService->variance($benchmark) * ReturnCalculator::TRADING_DAYS_PER_YEAR,
        ];
    }
}
