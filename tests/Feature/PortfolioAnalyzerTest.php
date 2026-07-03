<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioAnalyzerTest extends TestCase
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

        return $user;
    }

    public function test_analyzing_a_synced_portfolio_stores_a_snapshot_with_all_metrics(): void
    {
        $user = $this->syncedUser();

        $snapshot = app(PortfolioAnalyzer::class)->analyze($user);

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->as_of->isToday());
        $this->assertGreaterThan(0, $snapshot->total_value);

        $metrics = $snapshot->metrics;

        foreach ([
            'expected_return', 'volatility', 'beta', 'sharpe', 'sortino',
            'var_95', 'cvar_95', 'max_drawdown', 'hhi', 'effective_holdings',
            'diversification_ratio', 'largest_position', 'average_correlation',
            'stress_correlation', 'weights', 'allocations',
        ] as $key) {
            $this->assertArrayHasKey($key, $metrics);
        }

        $this->assertGreaterThan(0, $metrics['volatility']);
        $this->assertEqualsWithDelta(1.0, array_sum($metrics['weights']), 1e-6);
        $this->assertEqualsWithDelta(1.0, array_sum($metrics['allocations']['asset_class']), 1e-6);
        $this->assertSame($metrics['largest_position']['symbol'], 'AAPL');

        // Derayah is all-equity, so the class allocation has a single bucket.
        $this->assertSame(['equity'], array_keys($metrics['allocations']['asset_class']));
    }

    public function test_reanalyzing_the_same_day_updates_the_existing_snapshot(): void
    {
        $user = $this->syncedUser();
        $analyzer = app(PortfolioAnalyzer::class);

        $first = $analyzer->analyze($user);
        $second = $analyzer->analyze($user);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $user->portfolioSnapshots()->count());
    }

    public function test_analyzing_a_user_without_holdings_returns_null(): void
    {
        $this->assertNull(app(PortfolioAnalyzer::class)->analyze(User::factory()->create()));
    }

    public function test_disconnected_connections_are_excluded_from_analysis(): void
    {
        $user = $this->syncedUser();

        $user->connections()->first()->update(['status' => ConnectionStatus::Disconnected]);

        $this->assertNull(app(PortfolioAnalyzer::class)->analyze($user));
    }
}
