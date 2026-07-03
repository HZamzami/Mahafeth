<?php

namespace App\Services\Analytics;

use InvalidArgumentException;

/**
 * Builds the covariance matrix Σ — the central object of portfolio theory:
 * variances on the diagonal, covariances elsewhere.
 */
class CovarianceMatrixService
{
    /**
     * Sample covariance (n−1 denominator) of two aligned return series.
     *
     * @param  list<float>  $x
     * @param  list<float>  $y
     */
    public function covariance(array $x, array $y): float
    {
        $count = count($x);

        if ($count !== count($y)) {
            throw new InvalidArgumentException('Return series must be aligned to the same length.');
        }

        if ($count < 2) {
            return 0.0;
        }

        $meanX = array_sum($x) / $count;
        $meanY = array_sum($y) / $count;

        $sum = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $sum += ($x[$i] - $meanX) * ($y[$i] - $meanY);
        }

        return $sum / ($count - 1);
    }

    /**
     * Sample variance of a return series.
     *
     * @param  list<float>  $returns
     */
    public function variance(array $returns): float
    {
        return $this->covariance($returns, $returns);
    }

    /**
     * Full covariance matrix from aligned daily returns, annualized by default.
     *
     * @param  array<string, list<float>>  $alignedReturns  symbol => aligned returns
     * @return array<string, array<string, float>> symmetric matrix keyed by symbol
     */
    public function matrix(array $alignedReturns, bool $annualize = true): array
    {
        $symbols = array_keys($alignedReturns);
        $scale = $annualize ? ReturnCalculator::TRADING_DAYS_PER_YEAR : 1;
        $matrix = [];

        foreach ($symbols as $i => $a) {
            foreach ($symbols as $j => $b) {
                if ($j < $i) {
                    $matrix[$a][$b] = $matrix[$b][$a];

                    continue;
                }

                $matrix[$a][$b] = $this->covariance($alignedReturns[$a], $alignedReturns[$b]) * $scale;
            }
        }

        return $matrix;
    }
}
