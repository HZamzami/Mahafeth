<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Models\AlertRule;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\InvestmentPlan;
use App\Models\User;
use App\Services\Analytics\AlertEvaluator;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriftAlertTest extends TestCase
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

    public function test_no_plan_means_no_drift_metric_and_no_alert(): void
    {
        $user = $this->syncedUser();

        $snapshot = app(PortfolioAnalyzer::class)->analyze($user);

        $this->assertNull($snapshot->metrics['drift']);
        $this->assertNotContains('drift', array_column(app(AlertEvaluator::class)->forUser($user, $snapshot), 'identity'));
    }

    public function test_a_skewed_portfolio_raises_the_drift_alert_with_the_worst_symbol(): void
    {
        $user = $this->syncedUser();

        // The plan wants the whole portfolio in one symbol the user barely
        // holds, so every position is far off course.
        InvestmentPlan::factory()->create([
            'user_id' => $user->id,
            'weights' => ['MSFT' => 1.0],
        ]);

        $snapshot = app(PortfolioAnalyzer::class)->analyze($user);
        $drift = $snapshot->metrics['drift'];

        $this->assertNotNull($drift);
        $this->assertGreaterThan(AlertEvaluator::DRIFT_THRESHOLD, $drift['max']);
        $this->assertSame('MSFT', $drift['symbol']);

        $alerts = app(AlertEvaluator::class)->forUser($user, $snapshot);
        $alert = collect($alerts)->firstWhere('identity', 'drift');

        $this->assertNotNull($alert);
        $this->assertSame('amber', $alert['color']);
    }

    public function test_small_drift_stays_silent(): void
    {
        $user = $this->syncedUser();

        $snapshot = app(PortfolioAnalyzer::class)->analyze($user);

        // A plan mirroring the actual weights has nothing to complain about.
        InvestmentPlan::factory()->create([
            'user_id' => $user->id,
            'weights' => $snapshot->metrics['weights'],
        ]);

        $snapshot = app(PortfolioAnalyzer::class)->analyze($user->fresh());

        $this->assertEqualsWithDelta(0.0, $snapshot->metrics['drift']['max'], 1e-9);
        $this->assertNotContains('drift', array_column(app(AlertEvaluator::class)->forUser($user, $snapshot), 'identity'));
    }

    public function test_a_custom_allocation_drift_rule_fires(): void
    {
        $user = $this->syncedUser();

        InvestmentPlan::factory()->create(['user_id' => $user->id, 'weights' => ['MSFT' => 1.0]]);
        AlertRule::factory()->create([
            'user_id' => $user->id,
            'metric' => 'allocation_drift',
            'threshold' => 0.10,
            'enabled' => true,
        ]);

        $snapshot = app(PortfolioAnalyzer::class)->analyze($user);
        $alerts = app(AlertEvaluator::class)->forUser($user->fresh(), $snapshot);

        $this->assertNotNull(collect($alerts)->first(
            fn (array $alert): bool => str_starts_with($alert['identity'], 'custom:'),
        ));
    }
}
