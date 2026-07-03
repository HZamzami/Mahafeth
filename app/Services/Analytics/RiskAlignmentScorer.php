<?php

namespace App\Services\Analytics;

/**
 * Scores how closely the portfolio's actual volatility matches the target
 * volatility from the user's Investment Policy Statement:
 *
 *   Risk Alignment Score = 100 × max[0, 1 − |σp − σtarget| / σtarget]
 *
 * A perfect match scores 100; being off by a full target-width (in either
 * direction) scores 0.
 */
class RiskAlignmentScorer
{
    public function score(float $portfolioVolatility, float $targetVolatility): float
    {
        if ($targetVolatility <= 0) {
            return 0.0;
        }

        return 100 * max(0.0, 1 - abs($portfolioVolatility - $targetVolatility) / $targetVolatility);
    }
}
