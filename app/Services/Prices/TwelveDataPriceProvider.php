<?php

namespace App\Services\Prices;

use App\Contracts\PriceProvider;
use App\Enums\AssetClass;
use App\Models\Asset;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * End-of-day closes from the Twelve Data API. Crypto maps to USD pairs
 * (BTC to BTC/USD) and US symbols pass through unchanged. Tadawul symbols
 * (.SR and the TASI benchmark) are locked behind Twelve Data's paid plans,
 * so they route to Yahoo Finance instead. Cash needs no market series, so
 * it skips the APIs entirely. Symbols neither API can serve fall back to
 * the simulated series so analysis never loses an asset.
 */
class TwelveDataPriceProvider implements PriceProvider
{
    /**
     * Pause between requests so a full sync stays inside the free-tier
     * budget of 8 API credits per minute.
     */
    private const SECONDS_BETWEEN_REQUESTS = 8;

    public function __construct(
        private YahooFinancePriceProvider $tadawul,
        private SimulatedPriceProvider $fallback,
    ) {}

    public function fetchDailyCloses(array $symbols, CarbonInterface $from, CarbonInterface $to): array
    {
        $assetClasses = Asset::whereIn('symbol', $symbols)->pluck('asset_class', 'symbol');

        $series = [];
        $requested = false;

        foreach ($symbols as $symbol) {
            $assetClass = $assetClasses[$symbol] ?? null;
            $fetched = null;

            if ($this->isTadawul($symbol)) {
                // Yahoo has its own budget, so it never counts against ours.
                $fetched = $this->tadawul->fetchDailyCloses([$symbol], $from, $to)[$symbol] ?? null;
            } elseif ($assetClass !== AssetClass::Cash) {
                if ($requested) {
                    Sleep::for(self::SECONDS_BETWEEN_REQUESTS)->seconds();
                }

                $requested = true;
                $fetched = $this->fetchSymbol($symbol, $assetClass, $from, $to);
            }

            if ($fetched === null) {
                $fetched = $this->fallback->fetchDailyCloses([$symbol], $from, $to)[$symbol] ?? null;
            }

            if ($fetched !== null) {
                $series[$symbol] = $fetched;
            }
        }

        return $series;
    }

    private function isTadawul(string $symbol): bool
    {
        return str_ends_with($symbol, '.SR') || $symbol === 'TASI';
    }

    /**
     * @return ?array<string, float> [Y-m-d => close], null on failure
     */
    private function fetchSymbol(string $symbol, ?AssetClass $assetClass, CarbonInterface $from, CarbonInterface $to): ?array
    {
        $query = [
            'interval' => '1day',
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
            'outputsize' => 5000,
            'apikey' => config('services.twelvedata.key'),
        ];

        if ($assetClass === AssetClass::Crypto) {
            $query['symbol'] = $symbol.'/USD';
        } else {
            $query['symbol'] = $symbol;
        }

        try {
            $response = Http::baseUrl(config('services.twelvedata.base_url'))
                ->timeout(45)
                ->get('/time_series', $query)
                ->throw()
                ->json();

            if (($response['status'] ?? null) !== 'ok') {
                throw new \RuntimeException($response['message'] ?? 'unexpected response');
            }

            $series = [];

            foreach (array_reverse($response['values'] ?? []) as $bar) {
                $series[substr($bar['datetime'], 0, 10)] = (float) $bar['close'];
            }

            return $series === [] ? null : $series;
        } catch (\Throwable $exception) {
            Log::warning('Twelve Data price fetch failed, using simulated series.', [
                'symbol' => $symbol,
                'error' => str_replace((string) config('services.twelvedata.key'), '***', $exception->getMessage()),
            ]);

            return null;
        }
    }
}
