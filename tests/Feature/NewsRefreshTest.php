<?php

namespace Tests\Feature;

use App\Contracts\NewsProvider;
use App\Models\Asset;
use App\Models\NewsItem;
use App\Models\PortfolioSnapshot;
use App\Models\User;
use App\Services\News\MarketauxNewsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class NewsRefreshTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One live article, enough for a refresh to store something.
     */
    private function fakeMarketaux(): void
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
                    'published_at' => now()->toIso8601String(),
                    'entities' => [['symbol' => 'AAPL', 'industry' => 'Technology']],
                ]],
            ]),
        ]);
    }

    public function test_the_marketaux_provider_is_always_bound(): void
    {
        config(['services.marketaux.token' => null]);
        $this->assertInstanceOf(MarketauxNewsProvider::class, app(NewsProvider::class));

        config(['services.marketaux.token' => 'token-123']);
        $this->assertInstanceOf(MarketauxNewsProvider::class, app(NewsProvider::class));
    }

    public function test_the_refresh_command_upserts_items_and_prunes_stale_ones(): void
    {
        $this->fakeMarketaux();
        NewsItem::factory()->create(['published_at' => now()->subDays(30)]);

        $this->artisan('mahafeth:refresh-news')->assertSuccessful();

        $this->assertSame(0, NewsItem::where('published_at', '<', now()->subDays(14))->count());
        $this->assertGreaterThan(0, NewsItem::count());

        // Running twice must not duplicate headlines.
        $count = NewsItem::count();
        $this->artisan('mahafeth:refresh-news')->assertSuccessful();
        $this->assertSame($count, NewsItem::count());
    }

    public function test_the_news_card_refresh_button_pulls_fresh_items(): void
    {
        $this->fakeMarketaux();

        $user = User::factory()->create();
        PortfolioSnapshot::factory()->for($user)->create();
        $this->actingAs($user);

        Volt::test('dashboard.news-feed')
            ->call('refreshNews')
            ->assertDispatched('toast');

        $this->assertGreaterThan(0, NewsItem::count());
    }

    public function test_users_without_a_snapshot_cannot_refresh_and_see_no_refresh_button(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('dashboard.news-feed')
            ->assertDontSeeHtml('wire:click="refreshNews"')
            ->call('refreshNews')
            ->assertNotDispatched('toast');

        $this->assertSame(0, NewsItem::count());
    }

    public function test_a_refresh_prunes_leftover_synthetic_headlines(): void
    {
        // Url-less rows are the old synthetic headlines; they can no longer
        // be created and every refresh sweeps them out.
        NewsItem::factory()->create(['url' => null]);

        $this->fakeMarketaux();

        $this->artisan('mahafeth:refresh-news')->assertSuccessful();

        $this->assertSame(0, NewsItem::whereNull('url')->count());
        $this->assertSame(1, NewsItem::count());
    }

    public function test_marketaux_articles_map_to_news_items(): void
    {
        $this->fakeMarketaux();

        $this->artisan('mahafeth:refresh-news')->assertSuccessful();

        $item = NewsItem::where('headline', 'Apple ships new device')->firstOrFail();

        $this->assertSame(['AAPL'], $item->symbols);
        // The marketaux "Technology" industry is normalized to its GICS sector.
        $this->assertSame(['Information Technology'], $item->sectors);
        $this->assertSame('newswire', $item->source);
        $this->assertSame('https://newswire.example/apple-ships-new-device', $item->url);
    }

    public function test_marketaux_failures_return_no_items_instead_of_synthetic_ones(): void
    {
        config(['services.marketaux.token' => 'token-123']);
        Asset::factory()->create(['symbol' => 'AAPL']);

        Http::fake(['api.marketaux.com/*' => Http::response(status: 500)]);

        $this->assertSame([], app(NewsProvider::class)->fetchLatest());
    }
}
