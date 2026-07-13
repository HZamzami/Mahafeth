<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ExploreTest extends TestCase
{
    use RefreshDatabase;

    private function syncedUser(): User
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);

        return $user->fresh();
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/explore/TSLA')->assertRedirect('/login');
    }

    public function test_users_can_preview_an_instrument_they_do_not_hold(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/explore/TSLA')
            ->assertOk()
            ->assertSee('TSLA')
            ->assertSee(__("You don't hold this asset — data below comes from the market, not your accounts."))
            ->assertSee('tradingview')
            ->assertSeeLivewire('instruments.fundamentals')
            ->assertSeeLivewire('instruments.analyst-panel');
    }

    public function test_owned_instruments_redirect_to_the_holding_detail_page(): void
    {
        $user = $this->syncedUser();

        $this->actingAs($user)
            ->get('/explore/AAPL')
            ->assertRedirect(route('holdings.detail', 'AAPL'));
    }

    public function test_tadawul_symbols_map_to_the_tradingview_exchange_prefix(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/explore/4321.SR')
            ->assertOk()
            ->assertSee(urlencode('TADAWUL:4321'), escape: false);
    }

    public function test_the_explore_page_renders_the_search_and_movers_sections(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/explore')
            ->assertOk()
            ->assertSee(__('Explore'))
            ->assertSee(__('Search any stock, fund, or crypto…'))
            ->assertSeeLivewire('explore.movers');
    }

    public function test_visited_instruments_appear_under_recently_viewed(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/explore/TSLA')->assertOk();
        $this->get('/explore/AMZN')->assertOk();

        $this->get('/explore')
            ->assertOk()
            ->assertSee(__('Recently Viewed'))
            // Newest first, no duplicates.
            ->assertSeeInOrder(['AMZN', 'TSLA']);

        $this->assertSame(['AMZN', 'TSLA'], session('explore.recent'));
    }

    public function test_the_movers_section_lists_gainers_losers_and_actives(): void
    {
        $this->actingAs(User::factory()->create());

        Http::fake([
            'fc.yahoo.com' => Http::response(status: 404, headers: ['Set-Cookie' => 'A3=d=abc; Domain=.yahoo.com']),
            'query1.finance.yahoo.com/v1/test/getcrumb' => Http::response('crumb-token'),
            'query1.finance.yahoo.com/v1/finance/screener/*' => Http::response([
                'finance' => ['result' => [[
                    'quotes' => [[
                        'symbol' => 'NVDA',
                        'shortName' => 'NVIDIA Corporation',
                        'regularMarketPrice' => 205.88,
                        'regularMarketChangePercent' => 4.21,
                        'currency' => 'USD',
                    ]],
                ]]],
            ]),
        ]);

        Volt::test('explore.movers')
            ->assertSee(__('Top Gainers'))
            ->assertSee(__('Top Losers'))
            ->assertSee(__('Most Active'))
            ->assertSee('NVDA')
            ->assertSee('+4.21%');
    }

    public function test_the_movers_section_hides_when_the_screener_is_unavailable(): void
    {
        $this->actingAs(User::factory()->create());

        Http::fake(['*' => Http::response(status: 500)]);

        Volt::test('explore.movers')
            ->assertDontSee(__('Top Gainers'));
    }

    public function test_the_search_lists_owned_holdings_first(): void
    {
        $user = $this->syncedUser();
        $this->actingAs($user);

        Http::fake(['api.twelvedata.com/*' => Http::response(['status' => 'ok', 'data' => []])]);

        Volt::test('explore.index')
            ->set('query', 'AAPL')
            ->assertSee(__('Your Holdings'))
            ->assertSee('AAPL');
    }

    public function test_the_search_palette_lists_market_results_for_unowned_symbols(): void
    {
        $this->actingAs(User::factory()->create());

        Http::fake([
            'api.twelvedata.com/*' => Http::response([
                'status' => 'ok',
                'data' => [[
                    'symbol' => '2223',
                    'instrument_name' => 'Saudi Aramco Base Oil Company',
                    'exchange' => 'Tadawul',
                    'mic_code' => 'XSAU',
                    'country' => 'Saudi Arabia',
                    'currency' => 'SAR',
                    'instrument_type' => 'Common Stock',
                ]],
            ]),
        ]);

        Volt::test('explore.index')
            ->set('query', 'aramco')
            ->assertSee(__('Markets'))
            ->assertSee('Saudi Aramco Base Oil Company')
            ->assertSee('2223.SR');
    }

    public function test_search_failures_degrade_to_no_market_results(): void
    {
        $this->actingAs(User::factory()->create());

        Http::fake(['api.twelvedata.com/*' => Http::response(status: 500)]);

        Volt::test('explore.index')
            ->set('query', 'aramco')
            ->assertSee(__('No instruments found for :query.', ['query' => 'aramco']));
    }
}
