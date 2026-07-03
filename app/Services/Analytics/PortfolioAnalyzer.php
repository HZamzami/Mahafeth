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
    ) {}

    /**
     * Analyze the user's portfolio as of today. Returns null when there is
     * nothing to analyze (no connected holdings with price history).
     */
    public function analyze(User $user): ?PortfolioSnapshot
    {
        $from = now()->subYears((int) config('mahafeth.analysis_window_years'));
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

        $metrics = [
            'expected_return' => $annualReturn,
            'volatility' => $volatility,
            'beta' => $this->beta($portfolioReturns, $from),
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
            'weights' => $weights,
            'allocations' => [
                'asset_class' => $this->diversificationAnalyzer->groupWeights($weights, array_map(fn (array $asset) => $asset['asset_class'], $data['assets'])),
                'sector' => $this->diversificationAnalyzer->groupWeights($weights, array_filter(array_map(fn (array $asset) => $asset['sector'], $data['assets']))),
                'country' => $this->diversificationAnalyzer->groupWeights($weights, array_filter(array_map(fn (array $asset) => $asset['country'], $data['assets']))),
                'currency' => $this->diversificationAnalyzer->groupWeights($weights, array_map(fn (array $asset) => $asset['currency'], $data['assets'])),
            ],
        ];

        $attributes = ['total_value' => $totalValue, 'metrics' => $metrics];

        // Health scoring requires the investor's target volatility from
        // their IPS; without a risk profile the gauge stays locked.
        $riskProfile = $user->riskProfile;

        if ($riskProfile !== null) {
            $health = $this->healthScoreCalculator->calculate($metrics, $riskProfile->target_volatility);

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
     * Portfolio beta against the configured benchmark, aligned by date.
     *
     * @param  array<string, float>  $portfolioReturns  date => log return
     */
    private function beta(array $portfolioReturns, CarbonInterface $from): float
    {
        $benchmarkPrices = $this->assembler->benchmarkSeries($from);

        if ($benchmarkPrices === []) {
            return 0.0;
        }

        $benchmarkReturns = $this->returnCalculator->logReturns($benchmarkPrices);
        $commonDates = array_intersect_key($portfolioReturns, $benchmarkReturns);

        $portfolio = [];
        $benchmark = [];

        foreach (array_keys($commonDates) as $date) {
            $portfolio[] = $portfolioReturns[$date];
            $benchmark[] = $benchmarkReturns[$date];
        }

        return $this->riskAnalyzer->beta($portfolio, $benchmark);
    }
}
