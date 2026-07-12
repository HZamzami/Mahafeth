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
                    'outputsize' => self::MAX_RESULTS,
                    'apikey' => config('services.twelvedata.key'),
                ])
                ->throw()
                ->json();

            if (($response['status'] ?? null) !== 'ok') {
                throw new \RuntimeException($response['message'] ?? 'unexpected response');
            }

            return array_values(array_map(fn (array $match): array => [
                'symbol' => ($match['mic_code'] ?? null) === 'XSAU'
                    ? $match['symbol'].'.SR'
                    : $match['symbol'],
                'name' => $match['instrument_name'] ?? $match['symbol'],
                'exchange' => $match['exchange'] ?? '',
                'country' => $match['country'] ?? '',
                'currency' => $match['currency'] ?? '',
                'type' => $match['instrument_type'] ?? '',
            ], $response['data'] ?? []));
        } catch (\Throwable $exception) {
            Log::warning('Twelve Data symbol search failed.', [
                'query' => $query,
                'error' => str_replace((string) config('services.twelvedata.key'), '***', $exception->getMessage()),
            ]);

            return null;
        }
    }
}
