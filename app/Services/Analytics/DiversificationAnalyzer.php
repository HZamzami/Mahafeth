<?php

namespace App\Services\Analytics;

/**
 * Diversification and concentration metrics over portfolio weights.
 * Pure array-in/array-out.
 */
class DiversificationAnalyzer
{
    /**
     * Herfindahl–Hirschman Index: HHI = Σwi². Lower is more diversified.
     *
     * @param  array<string, float>  $weights
     */
    public function hhi(array $weights): float
    {
        $sum = 0.0;

        foreach ($weights as $weight) {
            $sum += $weight ** 2;
        }

        return $sum;
    }

    /**
     * Effective number of holdings: ENB = 1 / HHI. A 50-stock portfolio with
     * HHI 0.2 behaves like 5 equally weighted positions.
     *
     * @param  array<string, float>  $weights
     */
    public function effectiveHoldings(array $weights): float
    {
        $hhi = $this->hhi($weights);

        return $hhi > 0 ? 1 / $hhi : 0.0;
    }

    /**
     * Diversification ratio: DR = (Σwiσi) / σp. Higher means the portfolio
     * volatility sits further below the weighted sum of asset volatilities.
     *
     * @param  array<string, float>  $weights
     * @param  array<string, float>  $assetVolatilities  symbol => annualized σ
     */
    public function diversificationRatio(array $weights, array $assetVolatilities, float $portfolioVolatility): float
    {
        if ($portfolioVolatility <= 0) {
            return 0.0;
        }

        $weightedVolatility = 0.0;

        foreach ($weights as $symbol => $weight) {
            $weightedVolatility += $weight * ($assetVolatilities[$symbol] ?? 0.0);
        }

        return $weightedVolatility / $portfolioVolatility;
    }

    /**
     * Largest single position weight.
     *
     * @param  array<string, float>  $weights
     */
    public function largestPosition(array $weights): float
    {
        return $weights === [] ? 0.0 : max($weights);
    }

    /**
     * Aggregate weights by a grouping (sector, country, currency, class),
     * sorted descending.
     *
     * @param  array<string, float>  $weights  symbol => weight
     * @param  array<string, string>  $groups  symbol => group name
     * @return array<string, float> group => total weight
     */
    public function groupWeights(array $weights, array $groups): array
    {
        $totals = [];

        foreach ($weights as $symbol => $weight) {
            $group = $groups[$symbol] ?? 'Other';
            $totals[$group] = ($totals[$group] ?? 0.0) + $weight;
        }

        arsort($totals);

        return $totals;
    }
}
