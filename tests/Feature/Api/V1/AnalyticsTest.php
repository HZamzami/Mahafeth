<?php

namespace Tests\Feature\Api\V1;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_returns_empty_symbols_without_enough_holdings(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/analytics');

        $response->assertOk();
        $response->assertJsonPath('data.symbols', []);
    }

    public function test_analytics_returns_frontier_correlation_and_risk_decomposition(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/analytics');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['symbols', 'correlation', 'averageCorrelation', 'stressAverage', 'firstFactorShare', 'frontier', 'weights', 'rebalanceOrders', 'sectorContributions'],
        ]);
        $this->assertNotEmpty($response->json('data.symbols'));
    }

    public function test_rebalance_plan_returns_orders(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/analytics/rebalance-plan');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['orders']]);
    }

    public function test_stress_test_defaults_to_the_first_configured_scenario(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/analytics/stress-test');

        $response->assertOk();
        $response->assertJsonPath('data.scenario', array_key_first(config('mahafeth.stress_scenarios')));
        $response->assertJsonStructure(['data' => ['impact', 'positions']]);
    }

    public function test_stress_test_rejects_an_unknown_scenario(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/analytics/stress-test?scenario=made_up')->assertStatus(422);
    }

    private function syncedAndAnalyzedUser(): User
    {
        $user = User::factory()->create();
        RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);

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
