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

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertStatus(200);
    }

    public function test_the_dashboard_shows_the_analyzed_portfolio(): void
    {
        $user = $this->syncedAndAnalyzedUser();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('AAPL')
            ->assertSee('Technology');
    }

    public function test_the_allocation_donut_shows_asset_class_weights(): void
    {
        $this->actingAs($this->syncedAndAnalyzedUser());

        Volt::test('dashboard.asset-allocation')
            ->assertSee(__('Equities'))
            ->assertSee('100.0%');
    }

    public function test_the_health_card_shows_risk_metrics_and_can_refresh(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        $this->actingAs($user);

        $user->portfolioSnapshots()->delete();

        Volt::test('dashboard.health-score')
            ->call('refresh')
            ->assertSee(__('Diversification'));

        $this->assertSame(1, $user->portfolioSnapshots()->count());
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

        return $user;
    }

    public function test_the_performance_chart_plots_the_synced_portfolio(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);

        $this->actingAs($user);

        Volt::test('dashboard.performance-chart')
            ->assertViewHas('points', fn (array $points): bool => count($points) > 10);
    }
}
