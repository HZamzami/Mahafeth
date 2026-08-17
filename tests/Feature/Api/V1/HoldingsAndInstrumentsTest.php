<?php

namespace Tests\Feature\Api\V1;

use App\Actions\SyncConnection;
use App\Models\Asset;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HoldingsAndInstrumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_holdings_index_lists_the_users_positions(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/holdings');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['rows', 'totalValue', 'totalCost']]);
        $this->assertNotEmpty($response->json('data.rows'));
    }

    public function test_holdings_show_returns_position_detail_for_an_owned_symbol(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        Sanctum::actingAs($user);

        $asset = Asset::where('symbol', 'MSFT')->firstOrFail();

        $response = $this->getJson("/api/v1/holdings/{$asset->symbol}");

        $response->assertOk();
        $response->assertJsonPath('data.symbol', 'MSFT');
        $response->assertJsonStructure(['data' => ['quantity', 'value', 'transactions', 'filings', 'news']]);
    }

    public function test_holdings_show_404s_for_a_symbol_the_user_does_not_own(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $asset = Asset::factory()->create(['symbol' => 'UNOWNED']);

        $this->getJson("/api/v1/holdings/{$asset->symbol}")->assertNotFound();
    }

    public function test_income_calendar_returns_an_empty_shape_with_no_dividends(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/holdings/income-calendar');

        $response->assertOk();
        $response->assertJsonPath('data.trailing_total', 0);
    }

    public function test_what_if_simulates_a_hypothetical_buy(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/instruments/MSFT/what-if', [
            'amount' => 1000,
            'side' => 'buy',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['before', 'after', 'deltas', 'quantity']]);
    }

    public function test_what_if_rejects_an_invalid_side(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/instruments/MSFT/what-if', [
            'amount' => 1000,
            'side' => 'hold',
        ])->assertStatus(422);
    }

    public function test_explore_search_merges_owned_and_external_results(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        Sanctum::actingAs($user);

        Http::fake([
            'api.twelvedata.com/*' => Http::response([
                'status' => 'ok',
                'data' => [
                    ['symbol' => 'NVDA', 'instrument_name' => 'NVIDIA Corporation', 'exchange' => 'NASDAQ', 'country' => 'United States', 'currency' => 'USD', 'instrument_type' => 'Common Stock'],
                ],
            ]),
        ]);

        $response = $this->getJson('/api/v1/explore/search?query=NVDA');

        $response->assertOk();
        $symbols = collect($response->json('data'))->pluck('symbol');
        $this->assertContains('NVDA', $symbols);
    }

    public function test_explore_search_requires_at_least_two_characters(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/explore/search?query=N')->assertStatus(422);
    }

    private function syncedAndAnalyzedUser(): User
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);
        app(PortfolioAnalyzer::class)->analyze($user);

        return $user->fresh();
    }
}
