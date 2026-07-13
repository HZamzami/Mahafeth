<?php

namespace App\Services\Markets;

use App\Contracts\FundamentalsProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Company fundamentals from the Yahoo Finance quoteSummary API: profile,
 * quarterly revenue/earnings, analyst consensus, and key statistics. Like
 * the chart API it is keyless and covers Tadawul (.SR) symbols, but it
 * additionally demands a session cookie plus a matching "crumb" token.
 */
class YahooFundamentalsProvider implements FundamentalsProvider
{
    /**
     * Yahoo rejects requests with no User-Agent, and 429-blocks full fake
     * browser strings; the bare token is the one that passes.
     */
    private const USER_AGENT = 'Mozilla/5.0';

    private const MODULES = 'assetProfile,earnings,financialData,recommendationTrend,defaultKeyStatistics,summaryDetail,price';

    private const SESSION_CACHE_KEY = 'yahoo-fundamentals:session';

    public function fetch(string $symbol): ?array
    {
        $key = 'fundamentals:'.$symbol;
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached === false ? null : $cached;
        }

        $fundamentals = $this->fetchFresh($symbol);

        // Failures are cached briefly as a sentinel so an unavailable API
        // does not get re-hit on every page view.
        Cache::put($key, $fundamentals ?? false, $fundamentals === null ? now()->addHour() : now()->addDay());

        return $fundamentals;
    }

    private function fetchFresh(string $symbol): ?array
    {
        try {
            $result = $this->quoteSummary($symbol);

            return $result === null ? null : $this->parse($result);
        } catch (\Throwable $exception) {
            Log::warning('Yahoo Finance fundamentals fetch failed.', [
                'symbol' => $symbol,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return ?array<string, mixed> the raw quoteSummary result for the symbol
     */
    private function quoteSummary(string $symbol, bool $retried = false): ?array
    {
        $session = $this->session();

        $response = Http::baseUrl(config('services.yahoo_finance.base_url'))
            ->withUserAgent(self::USER_AGENT)
            ->withHeaders(['Cookie' => $session['cookie']])
            ->timeout(45)
            ->get('/v10/finance/quoteSummary/'.rawurlencode($symbol), [
                'modules' => self::MODULES,
                'crumb' => $session['crumb'],
            ]);

        // An expired session comes back as 401 "Invalid Crumb"; one refresh
        // of the cached cookie+crumb pair is the documented remedy.
        if ($response->status() === 401 && ! $retried) {
            Cache::forget(self::SESSION_CACHE_KEY);

            return $this->quoteSummary($symbol, retried: true);
        }

        $result = $response->throw()->json('quoteSummary.result.0');

        return is_array($result) ? $result : null;
    }

    /**
     * The cookie is issued by a plain hit on fc.yahoo.com (the response is
     * a 404, only the Set-Cookie header matters); the crumb endpoint then
     * echoes the token that must accompany every quoteSummary call.
     *
     * @return array{cookie: string, crumb: string}
     */
    private function session(): array
    {
        return Cache::remember(self::SESSION_CACHE_KEY, now()->addHours(6), function (): array {
            $cookieResponse = Http::withUserAgent(self::USER_AGENT)
                ->timeout(30)
                ->get(config('services.yahoo_finance.cookie_url'));

            $cookie = collect($cookieResponse->headers()['Set-Cookie'] ?? [])
                ->map(fn (string $header): string => trim(explode(';', $header, 2)[0]))
                ->implode('; ');

            $crumb = Http::baseUrl(config('services.yahoo_finance.base_url'))
                ->withUserAgent(self::USER_AGENT)
                ->withHeaders(['Cookie' => $cookie])
                ->timeout(30)
                ->get('/v1/test/getcrumb')
                ->throw()
                ->body();

            if ($cookie === '' || $crumb === '' || str_contains($crumb, '<')) {
                throw new \RuntimeException('Yahoo Finance session handshake failed.');
            }

            return ['cookie' => $cookie, 'crumb' => $crumb];
        });
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed> the FundamentalsProvider shape
     */
    private function parse(array $result): array
    {
        $profile = $result['assetProfile'] ?? [];
        $financial = $result['financialData'] ?? [];
        $summaryDetail = $result['summaryDetail'] ?? [];
        $keyStats = $result['defaultKeyStatistics'] ?? [];
        $price = $result['price'] ?? [];

        $quarters = collect($result['earnings']['financialsChart']['quarterly'] ?? [])
            ->map(fn (array $quarter): array => [
                'label' => $this->quarterLabel((string) $quarter['date']),
                'revenue' => $this->raw($quarter['revenue'] ?? null),
                'earnings' => $this->raw($quarter['earnings'] ?? null),
            ])
            ->values()
            ->all();

        $epsQuarters = collect($result['earnings']['earningsChart']['quarterly'] ?? [])
            ->map(fn (array $quarter): ?float => $this->raw($quarter['actual'] ?? null))
            ->values();

        return [
            'profile' => [
                'summary' => $profile['longBusinessSummary'] ?? null,
                'sector' => $profile['sector'] ?? null,
                'industry' => $profile['industry'] ?? null,
                'employees' => isset($profile['fullTimeEmployees']) ? (int) $profile['fullTimeEmployees'] : null,
                'website' => $profile['website'] ?? null,
                'city' => $profile['city'] ?? null,
                'country' => $profile['country'] ?? null,
            ],
            'quarters' => $quarters,
            'headline' => $this->headline($quarters, $epsQuarters->all()),
            'ratings' => $this->ratings($result['recommendationTrend']['trend'] ?? [], $financial['recommendationKey'] ?? null),
            'priceTarget' => $this->priceTarget($financial, $price),
            'stats' => [
                'marketCap' => $this->raw($summaryDetail['marketCap'] ?? $price['marketCap'] ?? null),
                'trailingPE' => $this->raw($summaryDetail['trailingPE'] ?? null),
                'trailingEps' => $this->raw($keyStats['trailingEps'] ?? null),
                'dividendYield' => $this->raw($summaryDetail['dividendYield'] ?? null),
                'debtToEquity' => $this->raw($financial['debtToEquity'] ?? null),
            ],
            'currency' => $price['currency'] ?? $summaryDetail['currency'] ?? null,
            'financialCurrency' => $financial['financialCurrency'] ?? $result['earnings']['financialCurrency'] ?? null,
        ];
    }

    /**
     * Latest reported quarter with quarter-over-quarter changes. Yahoo only
     * exposes the trailing four quarters, so the comparison is against the
     * prior quarter — a year-over-year delta is not computable from here.
     *
     * @param  list<array{label: string, revenue: ?float, earnings: ?float}>  $quarters
     * @param  list<?float>  $epsQuarters
     * @return array{quarterLabel: ?string, revenue: ?float, revenueChange: ?float, netIncome: ?float, netIncomeChange: ?float, eps: ?float, epsChange: ?float, netMargin: ?float}
     */
    private function headline(array $quarters, array $epsQuarters): array
    {
        $latest = $quarters !== [] ? $quarters[count($quarters) - 1] : null;
        $previous = count($quarters) > 1 ? $quarters[count($quarters) - 2] : null;

        $eps = $epsQuarters !== [] ? end($epsQuarters) : null;
        $previousEps = count($epsQuarters) > 1 ? $epsQuarters[count($epsQuarters) - 2] : null;

        $change = fn (?float $current, ?float $prior): ?float => $current !== null && $prior !== null && $prior != 0.0
            ? ($current - $prior) / abs($prior)
            : null;

        return [
            'quarterLabel' => $latest['label'] ?? null,
            'revenue' => $latest['revenue'] ?? null,
            'revenueChange' => $change($latest['revenue'] ?? null, $previous['revenue'] ?? null),
            'netIncome' => $latest['earnings'] ?? null,
            'netIncomeChange' => $change($latest['earnings'] ?? null, $previous['earnings'] ?? null),
            'eps' => $eps,
            'epsChange' => $change($eps, $previousEps),
            'netMargin' => isset($latest['revenue'], $latest['earnings']) && $latest['revenue'] > 0
                ? $latest['earnings'] / $latest['revenue']
                : null,
        ];
    }

    /**
     * Current-month analyst counts collapsed into the three buckets retail
     * investors actually read: buy, hold, sell.
     *
     * @param  list<array<string, mixed>>  $trend
     * @return ?array{buy: int, hold: int, sell: int, total: int, consensus: ?string}
     */
    private function ratings(array $trend, ?string $recommendationKey): ?array
    {
        $current = collect($trend)->firstWhere('period', '0m');

        if ($current === null) {
            return null;
        }

        $buy = (int) ($current['strongBuy'] ?? 0) + (int) ($current['buy'] ?? 0);
        $hold = (int) ($current['hold'] ?? 0);
        $sell = (int) ($current['sell'] ?? 0) + (int) ($current['strongSell'] ?? 0);
        $total = $buy + $hold + $sell;

        if ($total === 0) {
            return null;
        }

        return [
            'buy' => $buy,
            'hold' => $hold,
            'sell' => $sell,
            'total' => $total,
            'consensus' => match ($recommendationKey) {
                'strong_buy', 'buy', 'overweight' => 'buy',
                'hold', 'neutral' => 'hold',
                'underperform', 'underweight', 'sell', 'strong_sell' => 'sell',
                default => null,
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $financial
     * @param  array<string, mixed>  $price
     * @return ?array{low: float, mean: float, high: float, current: ?float}
     */
    private function priceTarget(array $financial, array $price): ?array
    {
        $low = $this->raw($financial['targetLowPrice'] ?? null);
        $mean = $this->raw($financial['targetMeanPrice'] ?? null);
        $high = $this->raw($financial['targetHighPrice'] ?? null);

        if ($low === null || $mean === null || $high === null) {
            return null;
        }

        return [
            'low' => $low,
            'mean' => $mean,
            'high' => $high,
            'current' => $this->raw($price['regularMarketPrice'] ?? null),
        ];
    }

    /**
     * Numeric fields arrive as {raw, fmt} objects; only raw matters here.
     */
    private function raw(mixed $node): ?float
    {
        if (is_array($node)) {
            $node = $node['raw'] ?? null;
        }

        return is_numeric($node) ? (float) $node : null;
    }

    /**
     * Yahoo labels quarters "2Q2025"; readers expect "Q2 2025".
     */
    private function quarterLabel(string $date): string
    {
        return preg_match('/^(\d)Q(\d{4})$/', $date, $matches) === 1
            ? "Q{$matches[1]} {$matches[2]}"
            : $date;
    }
}
