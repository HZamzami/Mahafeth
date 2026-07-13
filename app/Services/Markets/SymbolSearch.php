<?php

namespace App\Services\Markets;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Symbol lookup through Twelve Data's search endpoint so users can pull
 * up instruments they don't hold. Tadawul results map back onto our .SR
 * convention. Successful lookups are cached for a day to stay inside the
 * free-tier request budget; failures return no results without caching.
 */
class SymbolSearch
{
    private const CACHE_TTL_HOURS = 24;

    private const MAX_RESULTS = 8;

    /**
     * Twelve Data ranks purely by string relevance, so exotic venue pairs
     * (ETH/KRW on Bithumb) can precede the instruments people actually
     * search for; over-fetch and re-rank locally.
     */
    private const FETCH_SIZE = 20;

    private const TYPE_WEIGHTS = [
        'Common Stock' => 30,
        'ETF' => 20,
        'Digital Currency' => 10,
    ];

    private const MAJOR_EXCHANGES = ['Tadawul', 'NASDAQ', 'NYSE'];

    /**
     * @return list<array{symbol: string, name: string, exchange: string, country: string, currency: string, type: string}>
     */
    public function search(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $cacheKey = 'symbol-search:'.md5(mb_strtolower($query));

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $results = $this->fetch($query);

        if ($results !== null) {
            Cache::put($cacheKey, $results, now()->addHours(self::CACHE_TTL_HOURS));
        }

        return $results ?? [];
    }

    /**
     * @return ?list<array{symbol: string, name: string, exchange: string, country: string, currency: string, type: string}>
     */
    private function fetch(string $query): ?array
    {
        try {
            $response = Http::baseUrl(config('services.twelvedata.base_url'))
                ->timeout(15)
                ->get('/symbol_search', [
                    'symbol' => $query,
                    'outputsize' => self::FETCH_SIZE,
                    'apikey' => config('services.twelvedata.key'),
                ])
                ->throw()
                ->json();

            if (($response['status'] ?? null) !== 'ok') {
                throw new \RuntimeException($response['message'] ?? 'unexpected response');
            }

            $results = array_values(array_map(fn (array $match): array => [
                'symbol' => ($match['mic_code'] ?? null) === 'XSAU'
                    ? $match['symbol'].'.SR'
                    : $match['symbol'],
                'name' => $match['instrument_name'] ?? $match['symbol'],
                'exchange' => $match['exchange'] ?? '',
                'country' => $match['country'] ?? '',
                'currency' => $match['currency'] ?? '',
                'type' => $match['instrument_type'] ?? '',
            ], $response['data'] ?? []));

            return $this->curate($results);
        } catch (\Throwable $exception) {
            Log::warning('Twelve Data symbol search failed.', [
                'query' => $query,
                'error' => str_replace((string) config('services.twelvedata.key'), '***', $exception->getMessage()),
            ]);

            return null;
        }
    }

    /**
     * Normalize, dedupe, and re-rank the raw matches into the shortlist
     * users see: USD-quoted crypto collapsed to its base symbol (ETH/USD
     * becomes ETH, our Asset convention — and a slash never survives into
     * the /explore/{symbol} route), one row per symbol, familiar types and
     * venues first while preserving relevance order within ties.
     *
     * @param  list<array{symbol: string, name: string, exchange: string, country: string, currency: string, type: string}>  $results
     * @return list<array{symbol: string, name: string, exchange: string, country: string, currency: string, type: string}>
     */
    private function curate(array $results): array
    {
        return collect($results)
            ->map(function (array $match): ?array {
                if ($match['type'] === 'Digital Currency' && str_contains($match['symbol'], '/')) {
                    if (! str_ends_with($match['symbol'], '/USD')) {
                        return null;
                    }

                    $match['symbol'] = strstr($match['symbol'], '/', before_needle: true);
                }

                return str_contains($match['symbol'], '/') ? null : $match;
            })
            ->filter()
            ->unique('symbol')
            ->sortBy(fn (array $match, int $index): int => $index
                - (self::TYPE_WEIGHTS[$match['type']] ?? 0)
                - (in_array($match['exchange'], self::MAJOR_EXCHANGES, true) ? 5 : 0))
            ->take(self::MAX_RESULTS)
            ->values()
            ->all();
    }
}
