<?php

namespace App\Services\Analytics;

/**
 * Return calculations on price series. Pure array-in/array-out: callers
 * assemble price data (usually from PriceHistory) and pass it in.
 *
 * Price series are maps of Y-m-d date => close, in ascending date order.
 */
class ReturnCalculator
{
    public const TRADING_DAYS_PER_YEAR = 252;

    /**
     * Simple returns: Rt = (Pt − Pt−1) / Pt−1.
     *
     * @param  array<string, float>  $prices
     * @return array<string, float> keyed by the later date of each pair
     */
    public function simpleReturns(array $prices): array
    {
        $returns = [];
        $previous = null;

        foreach ($prices as $date => $close) {
            if ($previous !== null && $previous > 0) {
                $returns[$date] = ($close - $previous) / $previous;
            }

            $previous = $close;
        }

        return $returns;
    }

    /**
     * Log returns: rt = ln(Pt / Pt−1). Time-additive, preferred for statistics.
     *
     * @param  array<string, float>  $prices
     * @return array<string, float> keyed by the later date of each pair
     */
    public function logReturns(array $prices): array
    {
        $returns = [];
        $previous = null;

        foreach ($prices as $date => $close) {
            if ($previous !== null && $previous > 0 && $close > 0) {
                $returns[$date] = log($close / $previous);
            }

            $previous = $close;
        }

        return $returns;
    }

    /**
     * Log returns for several assets restricted to their shared dates, so the
     * resulting series are index-aligned for covariance and correlation work.
     *
     * @param  array<string, array<string, float>>  $priceSeries  symbol => [date => close]
     * @return array<string, list<float>> symbol => aligned log returns
     */
    public function alignedLogReturns(array $priceSeries): array
    {
        $commonDates = $this->commonDates($priceSeries);

        $aligned = [];

        foreach ($priceSeries as $symbol => $prices) {
            $shared = array_intersect_key($prices, $commonDates);
            ksort($shared);

            $aligned[$symbol] = array_values($this->logReturns($shared));
        }

        return $aligned;
    }

    /**
     * Total portfolio value per date: Σ quantity × close, over shared dates.
     *
     * @param  array<string, array<string, float>>  $priceSeries  symbol => [date => close]
     * @param  array<string, float>  $quantities  symbol => quantity held
     * @return array<string, float> date => portfolio value
     */
    public function portfolioValueSeries(array $priceSeries, array $quantities): array
    {
        $commonDates = $this->commonDates($priceSeries);
        ksort($commonDates);

        $values = [];

        foreach (array_keys($commonDates) as $date) {
            $total = 0.0;

            foreach ($priceSeries as $symbol => $prices) {
                $total += ($quantities[$symbol] ?? 0) * $prices[$date];
            }

            $values[$date] = $total;
        }

        return $values;
    }

    /**
     * Expected portfolio return: E(Rp) = Σ wi × E(Ri).
     *
     * @param  array<string, float>  $weights  symbol => portfolio weight
     * @param  array<string, float>  $expectedReturns  symbol => expected return
     */
    public function expectedReturn(array $weights, array $expectedReturns): float
    {
        $expected = 0.0;

        foreach ($weights as $symbol => $weight) {
            $expected += $weight * ($expectedReturns[$symbol] ?? 0);
        }

        return $expected;
    }

    /**
     * Annualize a daily log-return series: mean × trading days per year.
     *
     * @param  list<float>  $logReturns
     */
    public function annualizedReturn(array $logReturns): float
    {
        if ($logReturns === []) {
            return 0.0;
        }

        return (array_sum($logReturns) / count($logReturns)) * self::TRADING_DAYS_PER_YEAR;
    }

    /**
     * Dates present in every series, as a date-keyed set.
     *
     * @param  array<string, array<string, float>>  $priceSeries
     * @return array<string, true>
     */
    private function commonDates(array $priceSeries): array
    {
        $dateSets = array_map(fn (array $prices): array => array_fill_keys(array_keys($prices), true), $priceSeries);

        if ($dateSets === []) {
            return [];
        }

        return count($dateSets) === 1 ? reset($dateSets) : array_intersect_key(...array_values($dateSets));
    }
}
