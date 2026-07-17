<?php

namespace App\Services\Analytics;

use App\Enums\AssetClass;
use App\Enums\ShariahStatus;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds a day-one starter portfolio for an investor beginning with cash:
 * the efficient-frontier allocation whose volatility matches the IPS
 * target — the best expected return the investor's own risk budget can
 * buy — plus concrete buy orders and a Monte Carlo growth projection.
 */
class InvestmentPlanBuilder
{
    /**
     * Positions thinner than this are noise for a starter portfolio.
     */
    private const MIN_WEIGHT = 0.02;

    private const MAX_POSITIONS = 8;

    public function __construct(
        private PortfolioDataAssembler $assembler,
        private ReturnCalculator $returnCalculator,
        private CovarianceMatrixService $covarianceMatrixService,
        private EfficientFrontierService $frontierService,
        private GoalForecaster $forecaster,
    ) {}

    /**
     * @return ?array{
     *     weights: array<string, float>,
     *     orders: list<array{symbol: string, name: string, weight: float, value: float, quantity: float}>,
     *     metrics: array{expected_return: float, volatility: float, sharpe: float, risk_alignment: float, target_volatility: float, shariah_applied: bool},
     *     forecast: array{months: int, bands: array{p10: list<float>, p50: list<float>, p90: list<float>}, final: array{p10: float, p50: float, p90: float}}
     * }
     */
    public function build(User $user, float $amount, float $monthlyContribution = 0.0): ?array
    {
        $riskProfile = $user->riskProfile()->first();

        if ($riskProfile === null || $amount <= 0) {
            return null;
        }

        $shariahRequired = (bool) ($riskProfile->constraints['shariah_required'] ?? false);
        $universe = $this->universe($shariahRequired);

        if ($universe->isEmpty()) {
            return null;
        }

        $from = now()->subYears($riskProfile->time_horizon->analysisWindowYears());
        $priceSeries = $this->assembler->seriesFor($universe->pluck('symbol')->all(), $from);

        // Instruments with too little overlapping history would poison the
        // covariance estimates; require a meaningful sample.
        $priceSeries = array_filter($priceSeries, fn (array $series): bool => count($series) >= 60);

        if (count($priceSeries) < 2) {
            return null;
        }

        $aligned = $this->returnCalculator->alignedLogReturns($priceSeries);
        $covariance = $this->covarianceMatrixService->matrix($aligned);
        $expectedReturns = array_map(
            fn (array $returns): float => $this->returnCalculator->annualizedReturn($returns),
            $aligned,
        );

        $frontier = $this->frontierService->analyze(
            $expectedReturns,
            $covariance,
            currentWeights: array_fill_keys(array_keys($expectedReturns), 0.0),
            samples: 4000,
            targetVolatility: (float) $riskProfile->target_volatility,
        );

        $pick = $frontier['target'] ?? $frontier['recommended'];
        $weights = $this->practicalWeights($pick['weights']);

        // Re-price the pruned allocation: pruning shifts the point slightly.
        $expectedReturn = $this->returnCalculator->expectedReturn($weights, $expectedReturns);
        $volatility = $this->volatility($weights, $covariance);
        $riskFreeRate = (float) config('mahafeth.risk_free_rate');
        $targetVolatility = (float) $riskProfile->target_volatility;

        $forecast = $this->forecaster->forecast(
            currentValue: $amount,
            annualReturn: $expectedReturn,
            annualVolatility: $volatility,
            targetAmount: 0.0,
            months: $riskProfile->time_horizon->projectionYears() * 12,
            monthlyContribution: $monthlyContribution,
        );

        return [
            'weights' => $weights,
            'orders' => $this->orders($weights, $amount, $priceSeries, $universe),
            'metrics' => [
                'expected_return' => round($expectedReturn, 4),
                'volatility' => round($volatility, 4),
                'sharpe' => $volatility > 0 ? round(($expectedReturn - $riskFreeRate) / $volatility, 2) : 0.0,
                // Risk Alignment Score from the analytics reference:
                // 100 × max(0, 1 − |σp − σtarget| / σtarget).
                'risk_alignment' => round(100 * max(0.0, 1 - abs($volatility - $targetVolatility) / $targetVolatility), 1),
                'target_volatility' => $targetVolatility,
                'shariah_applied' => $shariahRequired,
            ],
            'forecast' => [
                'months' => $forecast['months'],
                'bands' => $forecast['bands'],
                'final' => $forecast['final'],
            ],
        ];
    }

    /**
     * Investable candidates: catalog instruments (never indices or cash),
     * restricted to compliant ones for Shariah-bound investors.
     *
     * @return Collection<int, Asset>
     */
    private function universe(bool $shariahRequired)
    {
        return Asset::query()
            ->where('is_benchmark', false)
            ->whereIn('asset_class', [AssetClass::Equity, AssetClass::Fund, AssetClass::Crypto])
            ->when($shariahRequired, fn ($query) => $query->where('shariah_status', ShariahStatus::Compliant))
            ->get();
    }

    /**
     * A starter portfolio should be tradeable by a human: drop dust
     * positions, cap the position count, renormalize to 1.
     *
     * @param  array<string, float>  $weights
     * @return array<string, float>
     */
    private function practicalWeights(array $weights): array
    {
        arsort($weights);

        $kept = array_slice(
            array_filter($weights, fn (float $weight): bool => $weight >= self::MIN_WEIGHT),
            0,
            self::MAX_POSITIONS,
            preserve_keys: true,
        );

        if ($kept === []) {
            $kept = array_slice($weights, 0, self::MAX_POSITIONS, preserve_keys: true);
        }

        $total = array_sum($kept);

        return array_map(fn (float $weight): float => round($weight / $total, 4), $kept);
    }

    /**
     * Concrete buy orders at the latest stored close in base currency.
     *
     * @param  array<string, float>  $weights
     * @param  array<string, array<string, float>>  $priceSeries
     * @param  Collection<int, Asset>  $universe
     * @return list<array{symbol: string, name: string, weight: float, value: float, quantity: float}>
     */
    private function orders(array $weights, float $amount, array $priceSeries, $universe): array
    {
        $minTradeValue = (float) config('mahafeth.min_trade_value');
        $assets = $universe->keyBy('symbol');
        $orders = [];

        foreach ($weights as $symbol => $weight) {
            $value = $weight * $amount;
            $price = end($priceSeries[$symbol]);

            if ($value < $minTradeValue || $price <= 0) {
                continue;
            }

            $orders[] = [
                'symbol' => $symbol,
                'name' => $assets[$symbol]?->localizedName() ?? $symbol,
                'weight' => $weight,
                'value' => round($value, 2),
                'quantity' => round($value / $price, 4),
            ];
        }

        return $orders;
    }

    /**
     * @param  array<string, float>  $weights
     * @param  array<string, array<string, float>>  $covariance
     */
    private function volatility(array $weights, array $covariance): float
    {
        $variance = 0.0;

        foreach ($weights as $a => $weightA) {
            foreach ($weights as $b => $weightB) {
                $variance += $weightA * $weightB * ($covariance[$a][$b] ?? 0.0);
            }
        }

        return sqrt(max(0.0, $variance));
    }
}
