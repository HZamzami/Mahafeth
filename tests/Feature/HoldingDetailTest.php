<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Connection;
use App\Models\Holding;
use App\Models\Institution;
use App\Models\PriceHistory;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HoldingDetailTest extends TestCase
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
