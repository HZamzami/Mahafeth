<?php

namespace App\Services\Analytics;

/**
 * Splits portfolio risk into systematic vs unsystematic parts and attributes
 * total variance to groups (sectors, countries) via marginal contributions.
 */
class RiskDecomposer
{
    /**
     * Systematic variance is β²·σ²m; the remainder of total variance is
     * unsystematic (diversifiable). Returned as shares of total variance.
     *
     * @return array{systematic_share: float, unsystematic_share: float}
     */
    public function systematicSplit(float $beta, float $benchmarkVariance, float $portfolioVariance): array
    {
        if ($portfolioVariance <= 0) {
            return ['systematic_share' => 0.0, 'unsystematic_share' => 0.0];
        }

        $systematic = min($portfolioVariance, ($beta ** 2) * $benchmarkVariance);

        return [
            'systematic_share' => $systematic / $portfolioVariance,
            'unsystematic_share' => ($portfolioVariance - $systematic) / $portfolioVariance,
        ];
    }

    /**
     * Share of total portfolio variance contributed by each group:
     * ci = wi·(Σw)i / σ²p, summed per group. Contributions sum to 1.
     *
     * @param  array<string, float>  $weights  symbol => weight
     * @param  array<string, array<string, float>>  $covarianceMatrix
     * @param  array<string, string>  $groups  symbol => group name
     * @return array<string, float> group => share of variance, sorted descending
     */
    public function contributions(array $weights, array $covarianceMatrix, array $groups): array
    {
        $portfolioVariance = 0.0;
        $marginals = [];

        foreach ($weights as $a => $weightA) {
            $sigmaW = 0.0;

            foreach ($weights as $b => $weightB) {
                $sigmaW += $weightB * ($covarianceMatrix[$a][$b] ?? 0.0);
            }

            $marginals[$a] = $weightA * $sigmaW;
            $portfolioVariance += $marginals[$a];
        }

        if ($portfolioVariance <= 0) {
            return [];
        }

        $totals = [];

        foreach ($marginals as $symbol => $marginal) {
            $group = $groups[$symbol] ?? 'Other';
            $totals[$group] = ($totals[$group] ?? 0.0) + $marginal / $portfolioVariance;
        }

        arsort($totals);

        return $totals;
    }
}
