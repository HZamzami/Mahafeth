<?php

namespace App\Services\Analytics;

/**
 * Correlation assessment: normalized co-movement between assets, the average
 * pairwise level, and the stressed correlations seen during market crises.
 */
class CorrelationAnalyzer
{
    /**
     * Default upward shift applied under stress: ρstress = ρ + δ(1 − ρ).
     */
    public const DEFAULT_STRESS_SHIFT = 0.3;

    /**
     * Correlation matrix from a covariance matrix: ρij = Cov(i,j) / (σi σj).
     *
     * @param  array<string, array<string, float>>  $covarianceMatrix
     * @return array<string, array<string, float>>
     */
    public function matrix(array $covarianceMatrix): array
    {
        $deviations = [];

        foreach ($covarianceMatrix as $symbol => $row) {
            $deviations[$symbol] = sqrt(max(0.0, $row[$symbol]));
        }

        $matrix = [];

        foreach ($covarianceMatrix as $a => $row) {
            foreach ($row as $b => $covariance) {
                $product = $deviations[$a] * $deviations[$b];

                $matrix[$a][$b] = $a === $b ? 1.0 : ($product > 0 ? $covariance / $product : 0.0);
            }
        }

        return $matrix;
    }

    /**
     * Mean of the off-diagonal pairwise correlations (upper triangle).
     *
     * @param  array<string, array<string, float>>  $correlationMatrix
     */
    public function averageCorrelation(array $correlationMatrix): float
    {
        $symbols = array_keys($correlationMatrix);
        $sum = 0.0;
        $pairs = 0;

        foreach ($symbols as $i => $a) {
            foreach (array_slice($symbols, $i + 1) as $b) {
                $sum += $correlationMatrix[$a][$b];
                $pairs++;
            }
        }

        return $pairs > 0 ? $sum / $pairs : 0.0;
    }

    /**
     * Stressed correlation: ρstress = ρ + δ(1 − ρ). Correlations converge
     * toward 1 in crises; δ controls how far.
     */
    public function stressCorrelation(float $correlation, float $shift = self::DEFAULT_STRESS_SHIFT): float
    {
        return $correlation + $shift * (1 - $correlation);
    }

    /**
     * Apply the stress shift to every off-diagonal pair.
     *
     * @param  array<string, array<string, float>>  $correlationMatrix
     * @return array<string, array<string, float>>
     */
    public function stressMatrix(array $correlationMatrix, float $shift = self::DEFAULT_STRESS_SHIFT): array
    {
        $matrix = [];

        foreach ($correlationMatrix as $a => $row) {
            foreach ($row as $b => $correlation) {
                $matrix[$a][$b] = $a === $b ? 1.0 : $this->stressCorrelation($correlation, $shift);
            }
        }

        return $matrix;
    }
}
