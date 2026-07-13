<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Enums\AssetClass;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Connection;
use App\Models\Holding;
use App\Models\Institution;
use App\Models\PriceHistory;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class HoldingDetailTest extends TestCase
{
    use RefreshDatabase;

    private function fakeYahoo(): void
    {
        Http::fake([
            'fc.yahoo.com' => Http::response(status: 404, headers: ['Set-Cookie' => 'A3=d=abc123; Domain=.yahoo.com']),
            'query1.finance.yahoo.com/v1/test/getcrumb' => Http::response('crumb-token'),
            'query1.finance.yahoo.com/v10/finance/quoteSummary/*' => Http::response(
                (string) file_get_contents(base_path('tests/fixtures/yahoo-quote-summary.json')),
            ),
        ]);
    }

    private function syncedUser(): User
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);
        app(PortfolioAnalyzer::class)->analyze($user->fresh());

        return $user->fresh();
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/holdings/AAPL')->assertRedirect('/login');
    }

    public function test_users_cannot_view_holdings_they_do_not_own(): void
    {
        $this->syncedUser();

        $this->actingAs(User::factory()->create())
            ->get('/holdings/AAPL')
            ->assertNotFound();
    }

    public function test_the_page_shows_the_position_and_the_tradingview_chart(): void
    {
        $user = $this->syncedUser();

        $this->actingAs($user)
            ->get('/holdings/AAPL')
            ->assertOk()
            ->assertSee(__('Your Position'))
            ->assertSee(__('Quantity'))
            ->assertSee(__('Portfolio Weight'))
            ->assertSee(__('Market Chart'))
            ->assertSee(__('Ask Mahafeth AI about this holding'))
            ->assertSee('s.tradingview.com', false);
    }

    public function test_saudi_symbols_map_to_the_tadawul_exchange(): void
    {
        $user = $this->syncedUser();

        $asset = Asset::factory()->create(['symbol' => '2222.SR', 'currency' => 'SAR']);
        $account = Account::factory()->create([
            'connection_id' => $user->connections()->first()->id,
        ]);
        Holding::factory()->create([
            'account_id' => $account->id,
            'asset_id' => $asset->id,
            'quantity' => 100,
            'avg_cost' => 27.5,
        ]);
        PriceHistory::factory()->create(['asset_id' => $asset->id, 'close' => 29.1]);

        $this->actingAs($user)
            ->get('/holdings/2222.SR')
            ->assertOk()
            ->assertSee('TADAWUL%3A2222', false);
    }

    public function test_saudi_equities_get_the_same_sections_as_us_equities(): void
    {
        $user = $this->syncedUser();

        $asset = Asset::factory()->create(['symbol' => '2222.SR', 'currency' => 'SAR', 'asset_class' => AssetClass::Equity]);
        $account = Account::factory()->create(['connection_id' => $user->connections()->first()->id]);
        Holding::factory()->create(['account_id' => $account->id, 'asset_id' => $asset->id]);
        PriceHistory::factory()->create(['asset_id' => $asset->id]);

        foreach (['2222.SR', 'AAPL'] as $symbol) {
            $this->actingAs($user)
                ->get("/holdings/{$symbol}")
                ->assertOk()
                ->assertSee(__('Market Chart'))
                ->assertSeeLivewire('instruments.fundamentals')
                ->assertSeeLivewire('instruments.analyst-panel')
                // Theme placeholders resolved client-side by the reactive
                // $flux.dark effect: the query one skins the chart toolbar,
                // the fragment colorTheme skins the page canvas.
                ->assertSee('theme=__THEME__', false)
                ->assertSee('%22colorTheme%22%3A%22__THEME__%22', false);
        }
    }

    public function test_prices_default_to_the_native_currency_with_a_persistent_toggle_to_base(): void
    {
        $user = $this->syncedUser();

        $asset = Asset::factory()->create(['symbol' => 'TSTX', 'currency' => 'USD']);
        $account = Account::factory()->create(['connection_id' => $user->connections()->first()->id]);
        Holding::factory()->create(['account_id' => $account->id, 'asset_id' => $asset->id, 'quantity' => 10, 'avg_cost' => 90.0]);
        PriceHistory::factory()->create(['asset_id' => $asset->id, 'date' => now()->subDay()->toDateString(), 'close' => 100.0]);
        PriceHistory::factory()->create(['asset_id' => $asset->id, 'date' => now()->toDateString(), 'close' => 110.0]);

        $this->actingAs($user);

        Volt::test('holdings.detail', ['asset' => $asset])
            ->assertSee('$ 110.00')
            // Config FX rate: 110 USD × 3.75 = 412.50 SAR.
            ->call('setCurrency', true)
            ->assertSee('⃁ 412.50')
            ->assertDontSee('$ 110.00');

        // The choice sticks for the next instrument visit.
        Volt::test('holdings.detail', ['asset' => $asset])
            ->assertSee('⃁ 412.50');
    }

    public function test_base_currency_assets_have_no_currency_toggle(): void
    {
        $user = $this->syncedUser();

        $asset = Asset::factory()->create(['symbol' => '2222.SR', 'currency' => 'SAR']);
        $account = Account::factory()->create(['connection_id' => $user->connections()->first()->id]);
        Holding::factory()->create(['account_id' => $account->id, 'asset_id' => $asset->id]);
        PriceHistory::factory()->create(['asset_id' => $asset->id]);

        $this->actingAs($user)
            ->get('/holdings/2222.SR')
            ->assertOk()
            ->assertDontSee('setCurrency');

        $this->actingAs($user)
            ->get('/holdings/AAPL')
            ->assertOk()
            ->assertSee('setCurrency');
    }

    public function test_the_page_shows_performance_stats_from_stored_closes(): void
    {
        $this->actingAs($this->syncedUser())
            ->get('/holdings/AAPL')
            ->assertOk()
            ->assertSee(__('Performance'))
            ->assertSee(__('52-Week Range'))
            ->assertSee(__('In Your Portfolio'));
    }

    public function test_equities_get_the_fundamentals_and_analyst_sections(): void
    {
        $this->actingAs($this->syncedUser())
            ->get('/holdings/AAPL')
            ->assertOk()
            ->assertSeeLivewire('instruments.fundamentals')
            ->assertSeeLivewire('instruments.analyst-panel');
    }

    public function test_crypto_gets_the_chart_but_no_fundamentals(): void
    {
        $user = $this->syncedUser();

        $asset = Asset::factory()->create(['symbol' => 'BTC', 'asset_class' => AssetClass::Crypto]);
        $account = Account::factory()->create(['connection_id' => $user->connections()->first()->id]);
        Holding::factory()->create(['account_id' => $account->id, 'asset_id' => $asset->id]);
        PriceHistory::factory()->create(['asset_id' => $asset->id]);

        $this->actingAs($user)
            ->get('/holdings/BTC')
            ->assertOk()
            ->assertSee(__('Market Chart'))
            ->assertDontSeeLivewire('instruments.fundamentals')
            ->assertDontSeeLivewire('instruments.analyst-panel');
    }

    public function test_the_fundamentals_section_renders_the_financials_and_company_profile(): void
    {
        $this->fakeYahoo();
        $this->actingAs($this->syncedUser());

        Volt::test('instruments.fundamentals', ['symbol' => 'MSFT'])
            ->assertSee(__('Financials'))
            ->assertSee(__('Total Revenue'))
            ->assertSee(__('vs prior quarter'))
            ->assertSee('Q1 2026')
            ->assertSee(__('About the Company'))
            ->assertSee('Microsoft Corporation develops')
            ->assertSee(__('Employees'))
            ->assertSee('228,000');
    }

    public function test_the_analyst_panel_renders_ratings_price_targets_and_key_stats(): void
    {
        $this->fakeYahoo();
        $this->actingAs($this->syncedUser());

        Volt::test('instruments.analyst-panel', ['symbol' => 'MSFT'])
            ->assertSee(__('Analyst Ratings'))
            ->assertSee(__('Based on :count analyst ratings.', ['count' => 56]))
            ->assertSee(__('Buy'))
            ->assertSee(__('Analyst Price Target (12 months)'))
            ->assertSee('559.86')
            ->assertSee(__('Key Stats'))
            ->assertSee(__('Market Cap'));
    }

    public function test_the_fundamentals_sections_hide_when_the_data_is_unavailable(): void
    {
        Http::fake(['*' => Http::response(status: 500)]);
        $this->actingAs($this->syncedUser());

        Volt::test('instruments.fundamentals', ['symbol' => 'MSFT'])
            ->assertDontSee(__('Total Revenue'));

        Volt::test('instruments.analyst-panel', ['symbol' => 'MSFT'])
            ->assertDontSee(__('Analyst Ratings'));
    }

    public function test_the_page_lists_the_users_transactions_for_the_asset(): void
    {
        $user = $this->syncedUser();
        $asset = Asset::where('symbol', 'AAPL')->firstOrFail();

        Transaction::create([
            'account_id' => $user->connections()->first()->accounts()->first()->id,
            'asset_id' => $asset->id,
            'type' => TransactionType::Buy,
            'quantity' => 10,
            'price' => 180.5,
            'amount' => 1805.0,
            'executed_at' => now()->subDays(3),
        ]);

        $this->actingAs($user)
            ->get('/holdings/AAPL')
            ->assertOk()
            ->assertSee(__('Recent Transactions'))
            ->assertSee(__('Buy'));
    }

    public function test_the_holdings_index_lists_every_position_with_detail_links(): void
    {
        $this->actingAs($this->syncedUser())
            ->get('/holdings')
            ->assertOk()
            ->assertSee(__('Holdings'))
            ->assertSee(__('Total Portfolio'))
            ->assertSee('AAPL')
            ->assertSee(route('holdings.detail', 'AAPL'));
    }

    public function test_the_holdings_index_shows_the_connect_prompt_without_holdings(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/holdings')
            ->assertOk()
            ->assertSee(__('No sources connected yet'));
    }

    public function test_the_report_links_holdings_to_the_detail_page(): void
    {
        $this->actingAs($this->syncedUser())
            ->get('/report')
            ->assertOk()
            ->assertSee(route('holdings.detail', 'AAPL'));
    }
}
