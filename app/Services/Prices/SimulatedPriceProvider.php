<?php

namespace App\Services\Prices;

use App\Contracts\PriceProvider;
use App\Services\OpenBanking\AssetCatalog;
use App\Services\OpenBanking\PriceSeriesGenerator;
use Carbon\CarbonInterface;

/**
 * Reproducible GBM price series from the shared asset catalog. The default
 * price source until a market-data API is configured, and the per-symbol
 * fallback when one fails.
 */
class SimulatedPriceProvider implements PriceProvider
{
    public function __construct(
        private AssetCatalog $assetCatalog,
        private PriceSeriesGenerator $priceSeriesGenerator,
    ) {}

    public function fetchDailyCloses(array $symbols, CarbonInterface $from, CarbonInterface $to): array
    {
        $series = [];

        foreach ($symbols as $symbol) {
            $params = $this->assetCatalog->simulationParams($symbol);

            if ($params === null) {
                continue;
            }

            $series[$symbol] = $this->priceSeriesGenerator->generate(
                symbol: $symbol,
                from: $from,
                to: $to,
                startPrice: $params['start'],
                drift: $params['drift'],
                volatility: $params['vol'],
                factorLoading: $params['loading'],
            );
        }

        return $series;
    }
}
