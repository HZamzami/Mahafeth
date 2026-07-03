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

    /**
     * PCA hidden-factor check: the share of total variance explained by the
     * first principal component of Σ (dominant eigenvalue / trace, via power
     * iteration). A share near 1 means the whole portfolio is driven by one
     * common factor — hidden concentration that pairwise numbers can miss.
     *
     * @param  array<string, array<string, float>>  $covarianceMatrix
     */
    public function firstFactorShare(array $covarianceMatrix): float
    {
        $symbols = array_keys($covarianceMatrix);
        $count = count($symbols);

        if ($count === 0) {
            return 0.0;
        }

        $trace = 0.0;
        foreach ($symbols as $symbol) {
            $trace += $covarianceMatrix[$symbol][$symbol];
        }

        if ($trace <= 0) {
            return 0.0;
        }

        if ($count === 1) {
            return 1.0;
        }

        // Power iteration for the dominant eigenvalue.
        $vector = array_fill_keys($symbols, 1 / sqrt($count));
        $eigenvalue = 0.0;

        for ($iteration = 0; $iteration < 100; $iteration++) {
            $product = [];
            $norm = 0.0;

            foreach ($symbols as $a) {
                $sum = 0.0;
                foreach ($symbols as $b) {
                    $sum += $covarianceMatrix[$a][$b] * $vector[$b];
                }
                $product[$a] = $sum;
                $norm += $sum ** 2;
            }

            $norm = sqrt($norm);

            if ($norm <= 0) {
                return 0.0;
            }

            $previous = $eigenvalue;
            $eigenvalue = $norm;

            foreach ($symbols as $symbol) {
                $vector[$symbol] = $product[$symbol] / $norm;
            }

            if (abs($eigenvalue - $previous) < 1e-12) {
                break;
            }
        }

        return min(1.0, $eigenvalue / $trace);
    }
}
