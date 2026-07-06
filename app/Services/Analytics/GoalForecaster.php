<?php

namespace App\Services\Analytics;

/**
 * Monte Carlo projection of portfolio value toward a financial goal.
 * Simulates monthly GBM steps with optional contributions and reports the
 * probability of reaching the target plus percentile bands for charting.
 * Seeded deterministically: the same inputs always produce the same result.
 */
class GoalForecaster
{
    private const SEED = 20260706;

    /**
     * @return array{
     *     probability: float,
     *     months: int,
     *     bands: array{p10: list<float>, p50: list<float>, p90: list<float>},
     *     final: array{p10: float, p50: float, p90: float}
     * }
     */
    public function forecast(
        float $currentValue,
        float $annualReturn,
        float $annualVolatility,
        float $targetAmount,
        int $months,
        float $monthlyContribution = 0.0,
        int $paths = 1000,
    ): array {
        $paths = max(100, min(5000, $paths));

        if ($months <= 0) {
            $reached = $currentValue >= $targetAmount ? 1.0 : 0.0;

            return [
                'probability' => $reached,
                'months' => 0,
                'bands' => ['p10' => [$currentValue], 'p50' => [$currentValue], 'p90' => [$currentValue]],
                'final' => ['p10' => $currentValue, 'p50' => $currentValue, 'p90' => $currentValue],
            ];
        }

        $monthlyDrift = ($annualReturn - 0.5 * $annualVolatility ** 2) / 12;
        $monthlyVol = $annualVolatility * sqrt(1 / 12);

        // values[path] holds the running value; bands collect per-month percentiles.
        $values = array_fill(0, $paths, $currentValue);
        $bands = ['p10' => [], 'p50' => [], 'p90' => []];
        $draws = $this->gaussianSeries(self::SEED, $paths * $months);

        for ($month = 0; $month < $months; $month++) {
            for ($path = 0; $path < $paths; $path++) {
                $shock = $draws[$month * $paths + $path];
                $values[$path] = $values[$path] * exp($monthlyDrift + $monthlyVol * $shock) + $monthlyContribution;
            }

            $sorted = $values;
            sort($sorted);

            $bands['p10'][] = round($sorted[(int) floor(0.10 * ($paths - 1))], 2);
            $bands['p50'][] = round($sorted[(int) floor(0.50 * ($paths - 1))], 2);
            $bands['p90'][] = round($sorted[(int) floor(0.90 * ($paths - 1))], 2);
        }

        $reached = count(array_filter($values, fn (float $value): bool => $value >= $targetAmount));

        return [
            'probability' => fdiv($reached, $paths),
            'months' => $months,
            'bands' => $bands,
            'final' => [
                'p10' => end($bands['p10']) ?: $currentValue,
                'p50' => end($bands['p50']) ?: $currentValue,
                'p90' => end($bands['p90']) ?: $currentValue,
            ],
        ];
    }

    /**
     * Deterministic standard-normal draws via an xorshift PRNG and Box-Muller.
     *
     * @return list<float>
     */
    private function gaussianSeries(int $seed, int $count): array
    {
        $state = ($seed & 0x7FFFFFFF) ?: 1;

        $uniform = function () use (&$state): float {
            $state ^= ($state << 13) & 0x7FFFFFFF;
            $state ^= $state >> 17;
            $state ^= ($state << 5) & 0x7FFFFFFF;

            return ($state % 1_000_000 + 1) / 1_000_001;
        };

        $draws = [];
        for ($i = 0; $i < $count; $i++) {
            $draws[] = sqrt(-2 * log($uniform())) * cos(2 * M_PI * $uniform());
        }

        return $draws;
    }
}
