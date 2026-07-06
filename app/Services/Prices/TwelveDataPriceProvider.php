<?php

namespace App\Services\Prices;

use App\Contracts\PriceProvider;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * End-of-day closes from the Twelve Data API. Tadawul symbols use the
 * exchange code XSAU (2222.SR maps to symbol 2222 on XSAU); US symbols
 * pass through unchanged. Symbols the API cannot serve fall back to the
 * simulated series so analysis never loses an asset.
 */
class TwelveDataPriceProvider implements PriceProvider
{
    public function __construct(private SimulatedPriceProvider $fallback) {}

    public function fetchDailyCloses(array $symbols, CarbonInterface $from, CarbonInterface $to): array
    {
        $series = [];

        foreach ($symbols as $symbol) {
            $fetched = $this->fetchSymbol($symbol, $from, $to);

            if ($fetched === null) {
                $fetched = $this->fallback->fetchDailyCloses([$symbol], $from, $to)[$symbol] ?? null;
            }

            if ($fetched !== null) {
                $series[$symbol] = $fetched;
            }
        }

        return $series;
    }

    /**
     * @return ?array<string, float> [Y-m-d => close], null on failure
     */
    private function fetchSymbol(string $symbol, CarbonInterface $from, CarbonInterface $to): ?array
    {
        $query = [
            'interval' => '1day',
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
            'outputsize' => 5000,
            'apikey' => config('services.twelvedata.key'),
        ];

        if (str_ends_with($symbol, '.SR')) {
            $query['symbol'] = substr($symbol, 0, -3);
            $query['mic_code'] = 'XSAU';
        } else {
            $query['symbol'] = $symbol;
        }

        try {
            $response = Http::baseUrl(config('services.twelvedata.base_url'))
                ->timeout(20)
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
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
