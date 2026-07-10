<?php

namespace Tests\Feature;

use App\Contracts\NewsProvider;
use App\Models\Asset;
use App\Models\NewsItem;
use App\Services\News\CuratedNewsProvider;
use App\Services\News\MarketauxNewsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NewsRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_curated_provider_is_bound_without_an_api_token(): void
    {
        config(['services.marketaux.token' => null]);

        $this->assertInstanceOf(CuratedNewsProvider::class, app(NewsProvider::class));
    }

    public function test_the_marketaux_provider_is_bound_with_an_api_token(): void
    {
        config(['services.marketaux.token' => 'token-123']);

        $this->assertInstanceOf(MarketauxNewsProvider::class, app(NewsProvider::class));
    }

    public function test_the_refresh_command_upserts_items_and_prunes_stale_ones(): void
    {
        NewsItem::factory()->create(['published_at' => now()->subDays(30)]);

        $this->artisan('mahafeth:refresh-news')->assertSuccessful();

        $this->assertSame(0, NewsItem::where('published_at', '<', now()->subDays(14))->count());
        $this->assertGreaterThan(0, NewsItem::count());

        // Running twice must not duplicate headlines.
        $count = NewsItem::count();
        $this->artisan('mahafeth:refresh-news')->assertSuccessful();
        $this->assertSame($count, NewsItem::count());
    }

    public function test_marketaux_articles_map_to_news_items(): void
    {
        config(['services.marketaux.token' => 'token-123']);
        Asset::factory()->create(['symbol' => 'AAPL']);

        Http::fake([
            'api.marketaux.com/*' => Http::response([
                'data' => [[
                    'title' => 'Apple ships new device',
                    'description' => str_repeat('word ', 400),
                    'source' => 'newswire',
                    'url' => 'https://newswire.example/apple-ships-new-device',
                    'published_at' => '2026-07-05T10:00:00Z',
                    'entities' => [['symbol' => 'AAPL', 'industry' => 'Technology']],
                ]],
            ]),
        ]);

        $this->artisan('mahafeth:refresh-news')->assertSuccessful();

        $item = NewsItem::where('headline', 'Apple ships new device')->firstOrFail();

        $this->assertSame(['AAPL'], $item->symbols);
        // The marketaux "Technology" industry is normalized to its GICS sector.
        $this->assertSame(['Information Technology'], $item->sectors);
        $this->assertSame('newswire', $item->source);
        $this->assertSame('https://newswire.example/apple-ships-new-device', $item->url);
    }

    public function test_marketaux_failures_fall_back_to_curated_headlines(): void
    {
        config(['services.marketaux.token' => 'token-123']);
        Asset::factory()->create(['symbol' => 'AAPL']);

        Http::fake(['api.marketaux.com/*' => Http::response(status: 500)]);

        $items = app(NewsProvider::class)->fetchLatest();

        $this->assertNotEmpty($items);
        $this->assertSame('Market Pulse', $items[0]['source']);
    }
}
