<?php

namespace App\Services\Analytics;

/**
 * Efficient frontier by Monte Carlo simulation: thousands of random
 * long-only weight vectors form a risk/return cloud whose upper envelope
 * approximates the frontier. The max-Sharpe sample is the tangency
 * portfolio. The current portfolio is always included in the candidate set,
 * so the tangency Sharpe is never worse than the current one.
 *
 * Seeded PRNG: identical inputs always produce identical results.
 */
class EfficientFrontierService
{
    private const SEED = 424242;

    private const FRONTIER_BINS = 30;

    /**
     * @param  array<string, float>  $expectedReturns  symbol => annualized expected return
     * @param  array<string, array<string, float>>  $covarianceMatrix  annualized Σ
     * @param  array<string, float>  $currentWeights  symbol => weight
     * @return array{
     *     cloud: list<array{risk: float, return: float}>,
     *     frontier: list<array{risk: float, return: float}>,
     *     tangency: array{weights: array<string, float>, risk: float, return: float, sharpe: float},
     *     current: array{risk: float, return: float, sharpe: float},
     *     efficiency_gap: float
     * }
     */
    public function analyze(
        array $expectedReturns,
        array $covarianceMatrix,
        array $currentWeights,
        ?float $riskFreeRate = null,
        int $samples = 6000,
    ): array {
        $riskFreeRate ??= (float) config('mahafeth.risk_free_rate');
        $symbols = array_keys($expectedReturns);

        $current = $this->evaluate($currentWeights, $expectedReturns, $covarianceMatrix, $riskFreeRate);

        $state = self::SEED;
        $cloud = [];
        $tangency = ['weights' => $currentWeights] + $current;

        for ($i = 0; $i < $samples; $i++) {
            $weights = $this->randomWeights($symbols, $state);
            $point = $this->evaluate($weights, $expectedReturns, $covarianceMatrix, $riskFreeRate);

            $cloud[] = ['risk' => $point['risk'], 'return' => $point['return']];

            if ($point['sharpe'] > $tangency['sharpe']) {
                $tangency = ['weights' => $weights] + $point;
            }
        }

        return [
            'cloud' => $cloud,
            'frontier' => $this->upperEnvelope($cloud),
            'tangency' => $tangency,
            'current' => $current,
            'efficiency_gap' => $tangency['sharpe'] - $current['sharpe'],
        ];
    }

    /**
     * @param  array<string, float>  $weights
     * @param  array<string, float>  $expectedReturns
     * @param  array<string, array<string, float>>  $covarianceMatrix
     * @return array{risk: float, return: float, sharpe: float}
     */
    private function evaluate(array $weights, array $expectedReturns, array $covarianceMatrix, float $riskFreeRate): array
    {
        $expected = 0.0;
        $variance = 0.0;

        foreach ($weights as $a => $weightA) {
            $expected += $weightA * ($expectedReturns[$a] ?? 0.0);

            foreach ($weights as $b => $weightB) {
                $variance += $weightA * $weightB * ($covarianceMatrix[$a][$b] ?? 0.0);
            }
        }

        $risk = sqrt(max(0.0, $variance));

        return [
            'risk' => $risk,
            'return' => $expected,
            'sharpe' => $risk > 0 ? ($expected - $riskFreeRate) / $risk : 0.0,
        ];
    }

    /**
     * Uniform random point on the simplex (Dirichlet(1)) via normalized
     * exponential draws from a deterministic xorshift PRNG.
     *
     * @param  list<string>  $symbols
     * @return array<string, float>
     */
    private function randomWeights(array $symbols, int &$state): array
    {
        $draws = [];
        $sum = 0.0;

        foreach ($symbols as $symbol) {
            $state ^= ($state << 13) & 0x7FFFFFFF;
            $state ^= $state >> 17;
            $state ^= ($state << 5) & 0x7FFFFFFF;

            $draws[$symbol] = -log(($state % 1_000_000 + 1) / 1_000_001);
            $sum += $draws[$symbol];
        }

        return array_map(fn (float $draw): float => $draw / $sum, $draws);
    }

    /**
     * Approximate frontier: bin the cloud by risk, keep each bin's best
     * return, and drop points dominated by a lower-risk bin.
     *
     * @param  list<array{risk: float, return: float}>  $cloud
     * @return list<array{risk: float, return: float}>
     */
    private function upperEnvelope(array $cloud): array
    {
        if ($cloud === []) {
            return [];
        }

        $risks = array_column($cloud, 'risk');
        $min = min($risks);
        $max = max($risks);

        if ($max <= $min) {
            return [];
        }

        $best = [];

        foreach ($cloud as $point) {
            $bin = (int) floor(($point['risk'] - $min) / ($max - $min) * (self::FRONTIER_BINS - 1));

            if (! isset($best[$bin]) || $point['return'] > $best[$bin]['return']) {
                $best[$bin] = $point;
            }
        }

        ksort($best);

        $frontier = [];
        $peak = -INF;

        foreach ($best as $point) {
            if ($point['return'] > $peak) {
                $frontier[] = $point;
                $peak = $point['return'];
            }
        }

        return $frontier;
    }
}
