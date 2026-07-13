<?php

namespace App\Services\Markets;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Today's US-market movers from Yahoo Finance's predefined screeners:
 * top gainers, top losers, and the most actively traded names. One call
 * per screen (the endpoint ignores comma-joined scrIds), cached together
 * for a market-fresh but rate-limit-friendly window.
 */
class YahooMarketMovers
{
    private const CACHE_KEY = 'market-movers';

    private const RESULTS_PER_SCREEN = 5;

    private const SCREENS = [
        'gainers' => 'day_gainers',
        'losers' => 'day_losers',
        'actives' => 'most_actives',
    ];

    public function __construct(private YahooSession $session) {}

    /**
     * @return ?array<string, list<array{symbol: string, name: string, price: ?float, changePercent: ?float, currency: ?string}>>
     */
    public function fetch(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached !== null) {
            return $cached === false ? null : $cached;
        }

        $movers = $this->fetchFresh();

        // Failures are cached briefly as a sentinel so an unavailable API
        // does not get re-hit on every page view.
        Cache::put(self::CACHE_KEY, $movers ?? false, $movers === null ? now()->addMinutes(10) : now()->addMinutes(20));

        return $movers;
    }

    /**
     * @return ?array<string, list<array{symbol: string, name: string, price: ?float, changePercent: ?float, currency: ?string}>>
     */
    private function fetchFresh(): ?array
    {
        try {
            $movers = [];

            foreach (self::SCREENS as $key => $screenId) {
                $movers[$key] = $this->screen($screenId);
            }

            return array_filter($movers) === [] ? null : $movers;
        } catch (\Throwable $exception) {
            Log::warning('Yahoo Finance market movers fetch failed.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return list<array{symbol: string, name: string, price: ?float, changePercent: ?float, currency: ?string}>
     */
    private function screen(string $screenId, bool $retried = false): array
    {
        $session = $this->session->headers();

        $response = Http::baseUrl(config('services.yahoo_finance.base_url'))
            ->withUserAgent(YahooSession::USER_AGENT)
            ->withHeaders(['Cookie' => $session['cookie']])
            ->timeout(30)
            ->get('/v1/finance/screener/predefined/saved', [
                'formatted' => 'false',
                'scrIds' => $screenId,
                'count' => self::RESULTS_PER_SCREEN,
                'crumb' => $session['crumb'],
            ]);

        // An expired session comes back as 401 "Invalid Crumb"; one refresh
        // of the cached cookie+crumb pair is the documented remedy.
        if ($response->status() === 401 && ! $retried) {
            $this->session->forget();

            return $this->screen($screenId, retried: true);
        }

        $quotes = $response->throw()->json('finance.result.0.quotes', []);

        return collect(is_array($quotes) ? $quotes : [])
            ->filter(fn (mixed $quote): bool => is_array($quote) && isset($quote['symbol']))
            ->map(fn (array $quote): array => [
                'symbol' => (string) $quote['symbol'],
                'name' => (string) ($quote['shortName'] ?? $quote['longName'] ?? $quote['symbol']),
                'price' => isset($quote['regularMarketPrice']) ? (float) $quote['regularMarketPrice'] : null,
                'changePercent' => isset($quote['regularMarketChangePercent']) ? (float) $quote['regularMarketChangePercent'] : null,
                'currency' => $quote['currency'] ?? null,
            ])
            ->take(self::RESULTS_PER_SCREEN)
            ->values()
            ->all();
    }
}
