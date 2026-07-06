<?php

namespace App\Contracts;

use Carbon\CarbonInterface;

interface PriceProvider
{
    /**
     * Fetch daily closing prices in each asset's native currency.
     *
     * @param  list<string>  $symbols
     * @return array<string, array<string, float>> symbol => [Y-m-d date => close]
     */
    public function fetchDailyCloses(array $symbols, CarbonInterface $from, CarbonInterface $to): array;
}
