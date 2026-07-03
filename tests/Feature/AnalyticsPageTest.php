<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
