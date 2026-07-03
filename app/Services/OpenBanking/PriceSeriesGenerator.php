<?php

namespace App\Services\OpenBanking;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Generates reproducible daily price series using geometric Brownian motion.
 *
 * Each asset's shocks blend a shared market factor with idiosyncratic noise,
 * so generated assets exhibit realistic cross-correlations for the analytics
 * engine to detect. Seeded per symbol: the same inputs always produce the
 * same series.
 */
class PriceSeriesGenerator
{
    private const TRADING_DAYS_PER_YEAR = 252;

    private const MARKET_FACTOR_SEED = 20260703;

    /**
     * Generate a business-day close series for one asset.
     *
     * @param  float  $startPrice  price at the start of the window
     * @param  float  $drift  annualized expected return (e.g. 0.12)
     * @param  float  $volatility  annualized volatility (e.g. 0.25)
     * @param  float  $factorLoading  0..1 weight on the shared market factor
     * @return array<string, float> [Y-m-d => close]
     */
    public function generate(
        string $symbol,
        CarbonInterface $from,
        CarbonInterface $to,
        float $startPrice,
        float $drift,
        float $volatility,
        float $factorLoading,
    ): array {
        $dates = $this->businessDays($from, $to);
        $marketShocks = $this->gaussianSeries(self::MARKET_FACTOR_SEED, count($dates));
        $ownShocks = $this->gaussianSeries(crc32($symbol), count($dates));

        $dt = 1 / self::TRADING_DAYS_PER_YEAR;
        $idiosyncraticWeight = sqrt(max(0.0, 1 - $factorLoading ** 2));

        $series = [];
        $price = $startPrice;

        foreach ($dates as $index => $date) {
            $shock = $factorLoading * $marketShocks[$index] + $idiosyncraticWeight * $ownShocks[$index];
            $price *= exp(($drift - 0.5 * $volatility ** 2) * $dt + $volatility * sqrt($dt) * $shock);
            $series[$date] = round($price, 4);
        }

        return $series;
    }

    /**
     * @return list<string> Y-m-d strings for Monday–Friday between the bounds
     */
    private function businessDays(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = [];
        $cursor = Carbon::parse($from->toDateString());

        while ($cursor->lessThanOrEqualTo($to)) {
            if ($cursor->isWeekday()) {
                $days[] = $cursor->toDateString();
            }
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * Deterministic standard-normal draws via an xorshift PRNG and Box–Muller.
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
