<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/analytics')->assertRedirect('/login');
    }

    public function test_users_without_holdings_see_the_empty_state(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/analytics')
            ->assertOk()
            ->assertSee(__('Connect at least two holdings to see correlation analytics.'));
    }

    public function test_the_correlation_matrix_is_rendered_for_a_synced_portfolio(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);

        $this->actingAs($user)
            ->get('/analytics')
            ->assertOk()
            ->assertSee('Correlation Matrix')
            ->assertSee('AAPL')
            ->assertSee('MSFT')
            ->assertSee('Average Correlation');
    }

    public function test_the_efficient_frontier_and_risk_sections_are_rendered(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);
        app(PortfolioAnalyzer::class)->analyze($user);

        $this->actingAs($user)
            ->get('/analytics')
            ->assertOk()
            ->assertSee(__('Efficient Frontier'))
            ->assertSee(__('Efficiency Gap'))
            ->assertSee(__('Suggested Allocation'))
            ->assertSee(__('Risk Decomposition'))
            ->assertSee(__('Risk by Sector'));
    }

    public function test_the_stress_scenario_panel_replays_shocks_on_the_portfolio(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);
        app(PortfolioAnalyzer::class)->analyze($user);
        $this->actingAs($user);

        // Derayah is tech-heavy, so the tech correction hits harder than
        // the broad market shock alone would.
        Volt::test('analytics.stress-scenarios')
            ->set('scenario', 'tech_correction')
            ->assertSee(__('Stress Test'))
            ->assertSee(__('Hardest-Hit Positions'))
            ->assertSee('AAPL')
            ->assertSee('%')
            ->assertSeeHtml('sm:hidden')
            ->assertSeeHtml('hidden sm:block')
            ->assertSeeHtml('bar-fill');
    }

    public function test_the_stress_panel_asks_for_analysis_without_a_snapshot(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('analytics.stress-scenarios')
            ->assertSee(__('Run the analysis to stress test your portfolio.'));
    }

    public function test_the_rebalancing_plan_renders_with_orders(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);
        app(PortfolioAnalyzer::class)->analyze($user);

        $this->actingAs($user)
            ->get('/analytics')
            ->assertOk()
            ->assertSee(__('Rebalancing Plan'))
            ->assertSee(__('Download CSV'));
    }

    public function test_the_rebalance_csv_download_contains_orders(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);
        app(PortfolioAnalyzer::class)->analyze($user);

        $this->actingAs($user);

        $response = Volt::test('analytics.index')->call('downloadRebalanceCsv');

        $response->assertFileDownloaded('mahafeth-rebalance-plan.csv');
    }
}
