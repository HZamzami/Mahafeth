<?php

namespace Tests\Feature;

use App\Contracts\FundamentalsProvider;
use App\Services\Markets\YahooFundamentalsProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YahooFundamentalsProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function fakeYahoo(): void
    {
        Http::fake([
            'fc.yahoo.com' => Http::response(status: 404, headers: [
                'Set-Cookie' => 'A3=d=abc123; Expires=Mon, 01 Jan 2029 00:00:00 GMT; Domain=.yahoo.com',
            ]),
            'query1.finance.yahoo.com/v1/test/getcrumb' => Http::response('crumb-token'),
            'query1.finance.yahoo.com/v10/finance/quoteSummary/*' => Http::response(
                (string) file_get_contents(base_path('tests/fixtures/yahoo-quote-summary.json')),
            ),
        ]);
    }

    public function test_the_contract_resolves_to_the_yahoo_provider(): void
    {
        $this->assertInstanceOf(YahooFundamentalsProvider::class, app(FundamentalsProvider::class));
    }

    public function test_it_parses_the_quote_summary_into_the_fundamentals_shape(): void
    {
        $this->fakeYahoo();

        $fundamentals = app(FundamentalsProvider::class)->fetch('MSFT');

        $this->assertNotNull($fundamentals);

        $this->assertSame('Technology', $fundamentals['profile']['sector']);
        $this->assertSame('Software - Infrastructure', $fundamentals['profile']['industry']);
        $this->assertSame(228000, $fundamentals['profile']['employees']);
        $this->assertStringContainsString('Microsoft Corporation develops', $fundamentals['profile']['summary']);

        $this->assertCount(4, $fundamentals['quarters']);
        $this->assertSame('Q2 2025', $fundamentals['quarters'][0]['label']);
        $this->assertSame(76441000000.0, $fundamentals['quarters'][0]['revenue']);
        $this->assertSame(27233000000.0, $fundamentals['quarters'][0]['earnings']);

        $this->assertSame('Q1 2026', $fundamentals['headline']['quarterLabel']);
        $this->assertSame(4.27, $fundamentals['headline']['eps']);
        $this->assertNotNull($fundamentals['headline']['revenueChange']);
        $this->assertNotNull($fundamentals['headline']['netMargin']);

        // 12 strong buy + 41 buy collapse into one Buy bucket.
        $this->assertSame(53, $fundamentals['ratings']['buy']);
        $this->assertSame(3, $fundamentals['ratings']['hold']);
        $this->assertSame(0, $fundamentals['ratings']['sell']);
        $this->assertSame(56, $fundamentals['ratings']['total']);
        $this->assertSame('buy', $fundamentals['ratings']['consensus']);

        $this->assertSame(400.0, $fundamentals['priceTarget']['low']);
        $this->assertSame(870.0, $fundamentals['priceTarget']['high']);
        $this->assertSame(390.515, $fundamentals['priceTarget']['current']);

        $this->assertSame(2900915388416.0, $fundamentals['stats']['marketCap']);
        $this->assertSame('USD', $fundamentals['currency']);
    }

    public function test_the_session_handshake_and_fundamentals_are_cached(): void
    {
        $this->fakeYahoo();

        $provider = app(FundamentalsProvider::class);
        $provider->fetch('MSFT');
        $provider->fetch('MSFT');

        // Cookie + crumb + one quoteSummary call; the second fetch is served
        // entirely from cache.
        Http::assertSentCount(3);
    }

    public function test_the_crumb_is_sent_with_the_session_cookie(): void
    {
        $this->fakeYahoo();

        app(FundamentalsProvider::class)->fetch('MSFT');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'quoteSummary')
            && str_contains($request->url(), 'crumb=crumb-token')
            && $request->hasHeader('Cookie', 'A3=d=abc123'));
    }

    public function test_an_expired_crumb_refreshes_the_session_once(): void
    {
        Cache::put('yahoo-fundamentals:session', ['cookie' => 'A3=stale', 'crumb' => 'stale'], now()->addHour());

        Http::fake([
            'fc.yahoo.com' => Http::response(status: 404, headers: ['Set-Cookie' => 'A3=fresh; Domain=.yahoo.com']),
            'query1.finance.yahoo.com/v1/test/getcrumb' => Http::response('fresh-crumb'),
            'query1.finance.yahoo.com/v10/finance/quoteSummary/*' => Http::sequence()
                ->push(['finance' => ['error' => ['description' => 'Invalid Crumb']]], 401)
                ->push((string) file_get_contents(base_path('tests/fixtures/yahoo-quote-summary.json'))),
        ]);

        $fundamentals = app(FundamentalsProvider::class)->fetch('MSFT');

        $this->assertNotNull($fundamentals);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'crumb=fresh-crumb'));
    }

    public function test_failures_return_null_and_are_cached_as_a_sentinel(): void
    {
        Http::fake(['*' => Http::response(status: 500)]);

        $provider = app(FundamentalsProvider::class);

        $this->assertNull($provider->fetch('MSFT'));

        $sent = 0;
        Http::assertSent(function () use (&$sent): bool {
            $sent++;

            return true;
        });
        $this->assertGreaterThan(0, $sent);

        // The sentinel short-circuits the next fetch without any HTTP call.
        Http::fake(['*' => Http::response(status: 500)]);
        $this->assertNull($provider->fetch('MSFT'));
        Http::assertNothingSent();
    }
}
