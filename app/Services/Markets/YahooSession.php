<?php

namespace App\Services\Markets;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The cookie+crumb session Yahoo Finance demands on its authenticated
 * JSON endpoints (quoteSummary, screeners). The cookie is issued by a
 * plain hit on fc.yahoo.com (the response is a 404, only the Set-Cookie
 * header matters); the crumb endpoint then echoes the token that must
 * accompany every call. Shared by every Yahoo service and cached as one
 * pair, since the crumb is only valid with the cookie it was minted for.
 */
class YahooSession
{
    /**
     * Yahoo rejects requests with no User-Agent, and 429-blocks full fake
     * browser strings; the bare token is the one that passes.
     */
    public const USER_AGENT = 'Mozilla/5.0';

    private const CACHE_KEY = 'yahoo:session';

    /**
     * @return array{cookie: string, crumb: string}
     */
    public function headers(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(6), function (): array {
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
     * Drop the cached pair after a 401 so the next call re-handshakes.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
