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

    /**
     * @param  array<string, float>  $expectedReturns  symbol => annualized expected return
     * @param  array<string, array<string, float>>  $covarianceMatrix  annualized Σ
     * @param  array<string, float>  $currentWeights  symbol => weight
     * @return array{
     *     cloud: list<array{risk: float, return: float}>,
     *     frontier: list<array{risk: float, return: float}>,
     *     tangency: array{weights: array<string, float>, risk: float, return: float, sharpe: float},
     *     current: array{risk: float, return: float, sharpe: float},
     *     efficiency_gap: float,
     *     target: ?array{weights: array<string, float>, risk: float, return: float, sharpe: float}
     * }
     */
    public function analyze(
        array $expectedReturns,
        array $covarianceMatrix,
        array $currentWeights,
        ?float $riskFreeRate = null,
        int $samples = 6000,
        ?float $targetVolatility = null,
    ): array {
        $riskFreeRate ??= (float) config('mahafeth.risk_free_rate');
        $symbols = array_keys($expectedReturns);

        $current = $this->evaluate($currentWeights, $expectedReturns, $covarianceMatrix, $riskFreeRate);

        $state = self::SEED;
        $cloud = [];
        $tangency = ['weights' => $currentWeights] + $current;
        $target = null;

        for ($i = 0; $i < $samples; $i++) {
            // Mix uniform portfolios with increasingly concentrated ones:
            // uniform sampling almost never visits the near-optimal edge of
            // the allocation space, which leaves an empty band between the
            // cloud and the true frontier. Concentrated samples fill it.
            $concentration = [1, 1, 2, 4][$i % 4];

            $weights = $this->randomWeights($symbols, $state, $concentration);
            $point = $this->evaluate($weights, $expectedReturns, $covarianceMatrix, $riskFreeRate);

            $cloud[] = ['risk' => $point['risk'], 'return' => $point['return']];

            if ($point['sharpe'] > $tangency['sharpe']) {
                $tangency = ['weights' => $weights] + $point;
            }

            if ($targetVolatility !== null && $this->beatsTarget($point, $target, $targetVolatility)) {
                $target = ['weights' => $weights] + $point;
            }
        }

        return [
            'cloud' => $cloud,
            'frontier' => $this->upperEnvelope($cloud),
            'tangency' => $tangency,
            'current' => $current,
            'efficiency_gap' => $tangency['sharpe'] - $current['sharpe'],
            'target' => $target,
        ];
    }

    /**
     * The investment-plan pick: the highest-return sample within the
     * investor's volatility budget. Samples inside the budget always beat
     * samples outside it; when nothing fits, the closest-risk sample
     * stands in.
     *
     * @param  array{risk: float, return: float, sharpe: float}  $point
     * @param  ?array{weights: array<string, float>, risk: float, return: float, sharpe: float}  $best
     */
    private function beatsTarget(array $point, ?array $best, float $targetVolatility): bool
    {
        if ($best === null) {
            return true;
        }

        $pointFits = $point['risk'] <= $targetVolatility;
        $bestFits = $best['risk'] <= $targetVolatility;

        return match (true) {
            $pointFits && $bestFits => $point['return'] > $best['return'],
            $pointFits !== $bestFits => $pointFits,
            default => abs($point['risk'] - $targetVolatility) < abs($best['risk'] - $targetVolatility),
        };
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
     * Random point on the simplex via normalized exponential draws from a
     * deterministic xorshift PRNG. A concentration exponent above 1 skews
     * the sample toward the edges and corners of the allocation space
     * (fewer dominant assets), which is where efficient portfolios live.
     *
     * @param  list<string>  $symbols
     * @return array<string, float>
     */
    private function randomWeights(array $symbols, int &$state, int $concentration = 1): array
    {
        $draws = [];
        $sum = 0.0;

        foreach ($symbols as $symbol) {
            $state ^= ($state << 13) & 0x7FFFFFFF;
            $state ^= $state >> 17;
            $state ^= ($state << 5) & 0x7FFFFFFF;

            $draw = -log(($state % 1_000_000 + 1) / 1_000_001);
            $draws[$symbol] = $draw ** $concentration;
            $sum += $draws[$symbol];
        }

        return array_map(fn (float $draw): float => $draw / $sum, $draws);
    }

    /**
     * The efficient frontier as the rising segment of the cloud's upper
     * convex hull (Andrew's monotone chain): a clean concave arc from the
     * minimum-variance tip up to the maximum-return portfolio. The hull
     * beyond the peak is inefficient by definition and is not drawn.
     *
     * @param  list<array{risk: float, return: float}>  $cloud
     * @return list<array{risk: float, return: float}>
     */
    private function upperEnvelope(array $cloud): array
    {
        if (count($cloud) < 3) {
            return $cloud;
        }

        $points = $cloud;

        usort($points, fn (array $a, array $b): int => [$a['risk'], $a['return']] <=> [$b['risk'], $b['return']]);

        $hull = [];

        foreach ($points as $point) {
            while (count($hull) >= 2 && $this->cross($hull[count($hull) - 2], $hull[count($hull) - 1], $point) >= 0) {
                array_pop($hull);
            }

            $hull[] = $point;
        }

        // Truncate at the maximum-return vertex — the frontier ends there.
        $peakIndex = 0;
        foreach ($hull as $index => $point) {
            if ($point['return'] > $hull[$peakIndex]['return']) {
                $peakIndex = $index;
            }
        }

        return array_slice($hull, 0, $peakIndex + 1);
    }

    /**
     * Cross product of (b − a) × (c − a) in (risk, return) space. Zero or
     * positive means the turn at b is not clockwise, so b is not part of
     * the upper hull.
     */
    private function cross(array $a, array $b, array $c): float
    {
        return ($b['risk'] - $a['risk']) * ($c['return'] - $a['return'])
            - ($b['return'] - $a['return']) * ($c['risk'] - $a['risk']);
    }
}
