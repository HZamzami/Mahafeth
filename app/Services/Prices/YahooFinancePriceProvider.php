<?php

namespace App\Services\Prices;

use App\Contracts\PriceProvider;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * End-of-day closes from the Yahoo Finance chart API. Covers the Tadawul
 * symbols the Twelve Data free tier locks behind a paid plan: .SR tickers
 * pass through unchanged (2222.SR) and the TASI benchmark maps to Yahoo's
 * ^TASI.SR index symbol. Requires no API key, only a browser User-Agent.
 */
class YahooFinancePriceProvider implements PriceProvider
{
    /**
     * Yahoo rejects requests with no User-Agent, and 429-blocks full fake
     * browser strings; the bare token is the one that passes.
     */
    private const USER_AGENT = 'Mozilla/5.0';

    public function fetchDailyCloses(array $symbols, CarbonInterface $from, CarbonInterface $to): array
    {
        $series = [];

        foreach ($symbols as $symbol) {
            $fetched = $this->fetchSymbol($symbol, $from, $to);

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
        $yahooSymbol = $symbol === 'TASI' ? '^TASI.SR' : $symbol;

        try {
            $response = Http::baseUrl(config('services.yahoo_finance.base_url'))
                ->withUserAgent(self::USER_AGENT)
                ->timeout(45)
                ->get('/v8/finance/chart/'.rawurlencode($yahooSymbol), [
                    'period1' => $from->startOfDay()->getTimestamp(),
                    'period2' => $to->endOfDay()->getTimestamp(),
                    'interval' => '1d',
                ])
                ->throw()
                ->json();

            $result = $response['chart']['result'][0] ?? null;

            if ($result === null) {
                throw new \RuntimeException($response['chart']['error']['description'] ?? 'unexpected response');
            }

            $timestamps = $result['timestamp'] ?? [];
            $closes = $result['indicators']['quote'][0]['close'] ?? [];
            $timezone = $result['meta']['exchangeTimezoneName'] ?? 'UTC';

            $series = [];

            foreach ($timestamps as $index => $timestamp) {
                $close = $closes[$index] ?? null;

                if ($close !== null) {
                    // Yahoo serves float32 values; rounding strips the representation noise.
                    $series[date_create('@'.$timestamp)->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d')] = round((float) $close, 4);
                }
            }

            return $series === [] ? null : $series;
        } catch (\Throwable $exception) {
            Log::warning('Yahoo Finance price fetch failed, using simulated series.', [
                'symbol' => $symbol,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
