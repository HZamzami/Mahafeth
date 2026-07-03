<?php

namespace App\Services\Analytics;

/**
 * Portfolio risk metrics: Markowitz variance/volatility, systematic risk
 * (beta), tail risk (VaR/CVaR), drawdown, and risk-adjusted performance.
 * Pure array-in/array-out.
 */
class RiskAnalyzer
{
    public function __construct(private CovarianceMatrixService $covarianceMatrixService) {}

    /**
     * Portfolio variance: σ²p = wᵀΣw.
     *
     * @param  array<string, float>  $weights  symbol => weight
     * @param  array<string, array<string, float>>  $covarianceMatrix
     */
    public function portfolioVariance(array $weights, array $covarianceMatrix): float
    {
        $variance = 0.0;

        foreach ($weights as $a => $weightA) {
            foreach ($weights as $b => $weightB) {
                $variance += $weightA * $weightB * ($covarianceMatrix[$a][$b] ?? 0.0);
            }
        }

        return $variance;
    }

    /**
     * Portfolio volatility: σp = √(wᵀΣw).
     *
     * @param  array<string, float>  $weights
     * @param  array<string, array<string, float>>  $covarianceMatrix
     */
    public function portfolioVolatility(array $weights, array $covarianceMatrix): float
    {
        return sqrt(max(0.0, $this->portfolioVariance($weights, $covarianceMatrix)));
    }

    /**
     * Beta: β = Cov(Rp, Rm) / Var(Rm).
     *
     * @param  list<float>  $portfolioReturns  aligned with the benchmark
     * @param  list<float>  $benchmarkReturns
     */
    public function beta(array $portfolioReturns, array $benchmarkReturns): float
    {
        $benchmarkVariance = $this->covarianceMatrixService->variance($benchmarkReturns);

        if ($benchmarkVariance <= 0) {
            return 0.0;
        }

        return $this->covarianceMatrixService->covariance($portfolioReturns, $benchmarkReturns) / $benchmarkVariance;
    }

    /**
     * Parametric Value at Risk: VaR = μ − zα·σ. Returns the return threshold;
     * a value of −0.25 reads "a worse-than −25% year is expected with
     * probability 1 − confidence".
     */
    public function valueAtRisk(float $expectedReturn, float $volatility, ?float $zScore = null): float
    {
        $zScore ??= (float) config('mahafeth.var_z_score');

        return $expectedReturn - $zScore * $volatility;
    }

    /**
     * Parametric Conditional VaR (expected shortfall) under normality:
     * CVaR = μ − σ·φ(z) / (1 − c).
     */
    public function conditionalValueAtRisk(float $expectedReturn, float $volatility, ?float $zScore = null, ?float $confidence = null): float
    {
        $zScore ??= (float) config('mahafeth.var_z_score');
        $confidence ??= (float) config('mahafeth.var_confidence');

        $density = exp(-($zScore ** 2) / 2) / sqrt(2 * M_PI);

        return $expectedReturn - $volatility * $density / (1 - $confidence);
    }

    /**
     * Maximum drawdown: worst peak-to-trough loss, as a positive fraction.
     *
     * @param  list<float>|array<string, float>  $values  portfolio value series in date order
     */
    public function maxDrawdown(array $values): float
    {
        $peak = 0.0;
        $maxDrawdown = 0.0;

        foreach ($values as $value) {
            $peak = max($peak, $value);

            if ($peak > 0) {
                $maxDrawdown = max($maxDrawdown, ($peak - $value) / $peak);
            }
        }

        return $maxDrawdown;
    }

    /**
     * Sharpe ratio: (Rp − Rf) / σp.
     */
    public function sharpeRatio(float $annualReturn, float $volatility, ?float $riskFreeRate = null): float
    {
        $riskFreeRate ??= (float) config('mahafeth.risk_free_rate');

        return $volatility > 0 ? ($annualReturn - $riskFreeRate) / $volatility : 0.0;
    }

    /**
     * Sortino ratio: (Rp − Rf) / σdown.
     */
    public function sortinoRatio(float $annualReturn, float $downsideDeviation, ?float $riskFreeRate = null): float
    {
        $riskFreeRate ??= (float) config('mahafeth.risk_free_rate');

        return $downsideDeviation > 0 ? ($annualReturn - $riskFreeRate) / $downsideDeviation : 0.0;
    }

    /**
     * Annualized downside deviation of daily returns below the target.
     *
     * @param  list<float>  $dailyReturns
     */
    public function downsideDeviation(array $dailyReturns, float $dailyTarget = 0.0): float
    {
        if ($dailyReturns === []) {
            return 0.0;
        }

        $sumSquares = 0.0;

        foreach ($dailyReturns as $return) {
            $shortfall = min(0.0, $return - $dailyTarget);
            $sumSquares += $shortfall ** 2;
        }

        return sqrt($sumSquares / count($dailyReturns)) * sqrt(ReturnCalculator::TRADING_DAYS_PER_YEAR);
    }
}
